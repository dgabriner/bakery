(function () {
    var root = document.querySelector('[data-baker-set]');
    if (!root) {
        return;
    }

    var statusNode = document.getElementById('bs-status-text');
    var staleNode = document.getElementById('bs-stale');
    var date = root.getAttribute('data-date') || '';
    var queueKey = 'baker-set-queue:' + date;
    var labels = {
        saved: root.getAttribute('data-saved') || '',
        saving: root.getAttribute('data-saving') || '',
        queued: root.getAttribute('data-queued') || '',
        error: root.getAttribute('data-error') || ''
    };
    var offline = false;
    var chain = Promise.resolve();
    var revision = root.getAttribute('data-revision') || '';

    function setStatus(text) {
        if (statusNode) {
            statusNode.textContent = text;
        }
    }

    function csrf() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') || '' : '';
    }

    function readQueue() {
        try {
            var parsed = JSON.parse(window.localStorage.getItem(queueKey) || '[]');
            return Array.isArray(parsed) ? parsed : [];
        } catch (err) {
            return [];
        }
    }

    function writeQueue(items) {
        window.localStorage.setItem(queueKey, JSON.stringify(items));
    }

    function remember(payload) {
        var items = readQueue();
        items.push(payload);
        writeQueue(items);
    }

    function showStale() {
        if (staleNode) {
            staleNode.hidden = false;
        }
        setStatus(root.getAttribute('data-stale') || '');
    }

    function stampRevision(next) {
        if (!next) {
            return;
        }
        revision = next;
        root.setAttribute('data-revision', next);
        root.querySelectorAll('input[name="revision"]').forEach(function (input) {
            input.value = next;
        });
    }

    function applyPatch(data) {
        if (!data) {
            return;
        }
        stampRevision(data.revision);
        if (data.reload) {
            var id = data.product_id || (data.patch && data.patch.product_id) || '';
            window.location.href = 'baker_set.php?date=' + encodeURIComponent(date) + '&notice=saved' + (id ? '#p-' + id : '');
            return;
        }
        var patch = data.patch;
        if (!patch || !patch.product_id) {
            setStatus(labels.saved);
            return;
        }
        var row = root.querySelector('[data-product="' + patch.product_id + '"]');
        if (!row) {
            window.location.href = 'baker_set.php?date=' + encodeURIComponent(date) + '&notice=saved#p-' + patch.product_id;
            return;
        }
        var form = row.querySelector('form');
        if (form) {
            var qty = form.querySelector('input[name="quantity"]');
            var has = form.querySelector('input[name="expected_has_plan"]');
            var expected = form.querySelector('input[name="expected_quantity"]');
            if (qty) {
                qty.value = String(patch.quantity);
            }
            if (has) {
                has.value = String(patch.expected_has_plan);
            }
            if (expected) {
                expected.value = String(patch.expected_quantity);
            }
        }
        var balance = row.querySelector('[data-balance]');
        if (balance) {
            balance.textContent = patch.balance_text || '';
            balance.setAttribute('data-kind', patch.balance || 'match');
        }
        var dough = patch.dough;
        if (dough) {
            var section = row.closest('[data-dough]');
            if (section) {
                var kg = section.querySelector('[data-dough-kg]');
                var batches = section.querySelector('[data-dough-batches]');
                if (kg) {
                    kg.textContent = dough.kg || '';
                }
                if (batches) {
                    batches.textContent = dough.batches || '';
                }
                var pull = section.querySelector('[data-dough-pull]');
                if (pull) {
                    pull.replaceChildren();
                    (dough.ingredients || []).forEach(function (ingredient) {
                        var item = document.createElement('li');
                        var name = document.createElement('span');
                        var grams = document.createElement('strong');
                        name.textContent = ingredient.name || '';
                        grams.textContent = ingredient.grams || '';
                        item.append(name, grams);
                        pull.append(item);
                    });
                }
            }
        }
        var undo = root.querySelector('button[value="undo"]');
        if (undo) {
            undo.disabled = !data.can_undo;
        }
        if (data.stale) {
            showStale();
        } else {
            setStatus(labels.saved);
        }
    }

    function payloadFromForm(form, action, quantity) {
        var data = {
            action: action,
            date: date,
            revision: revision,
            product_id: (form.querySelector('input[name="product_id"]') || {}).value || '',
            quantity: quantity,
            expected_has_plan: (form.querySelector('input[name="expected_has_plan"]') || {}).value || '0',
            expected_quantity: (form.querySelector('input[name="expected_quantity"]') || {}).value || '0'
        };
        return data;
    }

    function post(payload) {
        return fetch('baker_set.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-Token': csrf()
            },
            body: JSON.stringify(payload)
        }).then(function (response) {
            return response.json().catch(function () {
                return {};
            }).then(function (body) {
                if (response.status === 409 || body.conflict) {
                    var error = new Error(body.error || 'conflict');
                    error.conflict = true;
                    error.body = body;
                    throw error;
                }
                if (!response.ok || body.ok === false) {
                    var failed = new Error(body.error || labels.error);
                    failed.body = body;
                    throw failed;
                }
                return body;
            });
        });
    }

    function isNetwork(err) {
        return err instanceof TypeError || (err && err.network === true);
    }

    function run(payload) {
        setStatus(labels.saving);
        return post(payload).then(applyPatch).catch(function (err) {
            if (isNetwork(err)) {
                offline = true;
                remember(payload);
                setStatus(labels.queued);
                return;
            }
            if (err && err.conflict && err.body) {
                showStale();
                if (err.body.revision) {
                    stampRevision(err.body.revision);
                }
                return;
            }
            setStatus((err && err.message) || labels.error);
        });
    }

    function enqueue(payload) {
        if (offline) {
            remember(payload);
            setStatus(labels.queued);
            return;
        }
        chain = chain.then(function () {
            return run(payload);
        });
    }

    function flush() {
        if (offline && !navigator.onLine) {
            return;
        }
        var items = readQueue();
        if (!items.length) {
            offline = false;
            return;
        }
        writeQueue([]);
        offline = false;
        items.forEach(function (payload) {
            enqueue(payload);
        });
    }

    if (root.getAttribute('data-live') === '1') {
        root.querySelectorAll('.bs-stepper').forEach(function (form) {
            form.addEventListener('submit', function (event) {
                event.preventDefault();
                var submitter = event.submitter;
                var action = submitter && submitter.value ? submitter.value : 'set';
                var input = form.querySelector('input[name="quantity"]');
                var current = input ? parseInt(input.value || '0', 10) : 0;
                if (!isFinite(current) || current < 0) {
                    current = 0;
                }
                if (action === 'step-up') {
                    current += 1;
                } else if (action === 'step-down') {
                    current = Math.max(0, current - 1);
                }
                if (input) {
                    input.value = String(current);
                }
                enqueue(payloadFromForm(form, 'set', String(current)));
            });
        });

        root.querySelectorAll('form').forEach(function (form) {
            if (form.classList.contains('bs-stepper') || form.method.toLowerCase() !== 'post') {
                return;
            }
            form.addEventListener('submit', function (event) {
                var submitter = event.submitter;
                var action = submitter && submitter.value ? submitter.value : '';
                if (!action || action === 'add') {
                    return;
                }
                event.preventDefault();
                enqueue({
                    action: action,
                    date: date,
                    revision: revision
                });
            });
        });
    }

    window.addEventListener('online', flush);
    flush();

    window.setInterval(function () {
        if (offline || document.hidden) {
            return;
        }
        fetch('baker_set.php?ajax=revision&date=' + encodeURIComponent(date), {
            headers: { 'Accept': 'application/json' },
            credentials: 'same-origin'
        }).then(function (response) {
            return response.ok ? response.json() : null;
        }).then(function (body) {
            if (!body || !body.revision || body.revision === revision) {
                return;
            }
            showStale();
        }).catch(function () {
            return null;
        });
    }, 20000);
})();
