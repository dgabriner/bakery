<?php
/**
 * Photo to Text experiment.
 * Images are held only for the current request and are never written to disk.
 */
define('ACCESS_ALLOWED', true);

// A PHP warning must never turn this AJAX endpoint into an HTML response.
$photoTextIsPost = (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST');
$photoTextBufferLevel = 0;
if ($photoTextIsPost) {
    $photoTextBufferLevel = ob_get_level();
    ob_start();
}

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';

if ($photoTextIsPost) {
    // config.php enables error display locally; keep API responses valid JSON instead.
    ini_set('display_errors', '0');
    while (ob_get_level() > $photoTextBufferLevel) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');

    try {
        bakery_require_csrf();
        if (!function_exists('curl_init')) {
            throw new RuntimeException('PHP cURL is required for AI transcription.');
        }
        if (!isset($_FILES['photo']) || !is_array($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Please choose or take a photo first.');
        }
        if ((int) $_FILES['photo']['size'] > MAX_UPLOAD_SIZE) {
            throw new RuntimeException('The photo must be 5 MB or smaller.');
        }

        $imageInfo = @getimagesize($_FILES['photo']['tmp_name']);
        $allowedMimeTypes = ['image/jpeg', 'image/png', 'image/webp'];
        $mimeType = is_array($imageInfo) ? ($imageInfo['mime'] ?? '') : '';
        if (!in_array($mimeType, $allowedMimeTypes, true)) {
            throw new RuntimeException('Use a JPEG, PNG, or WebP image.');
        }

        $apiKey = $_ENV['OPENAI_API_KEY'] ?? getenv('OPENAI_API_KEY');
        if (!$apiKey) {
            throw new RuntimeException('AI mode is not configured. Add OPENAI_API_KEY to .env (local) or the server environment. You can still use the Cursor/Codex handoff below without a key.');
        }

        $image = file_get_contents($_FILES['photo']['tmp_name']);
        if ($image === false) {
            throw new RuntimeException('Could not read the uploaded photo.');
        }

        $instruction = 'Transcribe every piece of visible text in this image. Preserve line breaks, spelling, numbers, and punctuation. Return only the transcription. If part of the image is unclear, write [unclear] in its place; do not return an empty response.';
        $model = $_ENV['OPENAI_VISION_MODEL'] ?? getenv('OPENAI_VISION_MODEL') ?: 'gpt-4.1-mini';
        $payload = [
            'model' => $model,
            'input' => [[
                'role' => 'user',
                'content' => [
                    ['type' => 'input_text', 'text' => $instruction],
                    [
                        'type' => 'input_image',
                        'image_url' => 'data:' . $mimeType . ';base64,' . base64_encode($image),
                        'detail' => 'high',
                    ],
                ],
            ]],
        ];
        // OCR does not need multi-step reasoning. GPT-5.6 defaults to medium, so opt out explicitly.
        if (strpos($model, 'gpt-5.6-') === 0) {
            $reasoningEffort = strtolower((string) ($_ENV['OPENAI_VISION_REASONING_EFFORT'] ?? getenv('OPENAI_VISION_REASONING_EFFORT') ?: 'none'));
            if (in_array($reasoningEffort, ['none', 'low', 'medium', 'high', 'xhigh', 'max'], true)) {
                $payload['reasoning'] = ['effort' => $reasoningEffort];
            }
        }

        $curl = curl_init('https://api.openai.com/v1/responses');
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
        ]);
        $responseBody = curl_exec($curl);
        $httpStatus = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $curlError = curl_error($curl);
        curl_close($curl);

        if ($responseBody === false || $curlError !== '') {
            throw new RuntimeException('AI request failed: ' . ($curlError ?: 'unknown network error'));
        }
        $response = json_decode($responseBody, true);
        if ($httpStatus < 200 || $httpStatus >= 300) {
            $message = $response['error']['message'] ?? 'The AI service returned an error.';
            throw new RuntimeException($message);
        }
        $text = bakery_ai_response_text($response);
        if ($text === '') {
            $refusal = bakery_ai_response_refusal($response);
            $diagnostic = bakery_ai_response_diagnostic($response);
            throw new RuntimeException($refusal !== '' ? 'The AI service declined this image: ' . $refusal : 'The AI service returned no text. ' . $diagnostic);
        }

        echo json_encode([
            'success' => true,
            'text' => $text,
            'model' => (string) ($response['model'] ?? $payload['model']),
            'usage' => [
                'input_tokens' => (int) ($response['usage']['input_tokens'] ?? 0),
                'output_tokens' => (int) ($response['usage']['output_tokens'] ?? 0),
            ],
        ], JSON_INVALID_UTF8_SUBSTITUTE);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_INVALID_UTF8_SUBSTITUTE);
    }
    exit;
}

/** Read text from both the convenience field and the structured Responses output. */
function bakery_ai_response_text(array $response) {
    $text = trim((string) ($response['output_text'] ?? ''));
    if ($text !== '') {
        return $text;
    }
    $parts = [];
    foreach (($response['output'] ?? []) as $message) {
        foreach (($message['content'] ?? []) as $content) {
            if (($content['type'] ?? '') === 'output_text' && is_string($content['text'] ?? null)) {
                $parts[] = $content['text'];
            }
        }
    }
    return trim(implode("\n", $parts));
}

/** Extract a model refusal so the user gets a useful explanation. */
function bakery_ai_response_refusal(array $response) {
    foreach (($response['output'] ?? []) as $message) {
        foreach (($message['content'] ?? []) as $content) {
            if (($content['type'] ?? '') === 'refusal' && is_string($content['refusal'] ?? null)) {
                return trim($content['refusal']);
            }
        }
    }
    return '';
}

/** Non-sensitive diagnostic fields for troubleshooting unexpected API response shapes. */
function bakery_ai_response_diagnostic(array $response) {
    $types = [];
    foreach (($response['output'] ?? []) as $message) {
        foreach (($message['content'] ?? []) as $content) {
            if (!empty($content['type'])) {
                $types[] = (string) $content['type'];
            }
        }
    }
    $status = (string) ($response['status'] ?? 'unknown');
    $model = (string) ($response['model'] ?? 'unknown');
    return 'API status: ' . $status . '; model: ' . $model . '; returned content: ' . ($types ? implode(', ', array_unique($types)) : 'none') . '.';
}

$configuredModel = $_ENV['OPENAI_VISION_MODEL'] ?? getenv('OPENAI_VISION_MODEL') ?: 'gpt-4.1-mini';
$configuredReasoning = $_ENV['OPENAI_VISION_REASONING_EFFORT'] ?? getenv('OPENAI_VISION_REASONING_EFFORT') ?: 'none';
$page_title = 'Photo to Text';
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/nav.php';
?>
<style>
.photo-text-page { max-width: 820px; margin: 2rem auto; padding: 0 1rem 2rem; }
.photo-text-card { background: #fff; border: 1px solid #d9e2e8; border-radius: 12px; padding: 1.5rem; box-shadow: 0 2px 8px rgba(20, 50, 70, .06); }
.photo-text-actions { display: flex; gap: .75rem; flex-wrap: wrap; margin: 1rem 0; }
.photo-text-button { border: 0; border-radius: 7px; padding: .75rem 1rem; background: #156f86; color: #fff; font: inherit; font-weight: 700; cursor: pointer; }
.photo-text-button.secondary { background: #eaf2f4; color: #12475a; }
.photo-text-button:disabled { opacity: .6; cursor: wait; }
.photo-preview { display: none; max-width: 100%; max-height: 390px; object-fit: contain; border-radius: 8px; border: 1px solid #d9e2e8; margin-top: 1rem; }
.photo-text-result { width: 100%; min-height: 180px; box-sizing: border-box; padding: .8rem; border: 1px solid #afc3cc; border-radius: 7px; font: 15px/1.45 ui-monospace, Consolas, monospace; }
.photo-text-note { background: #f0f7f9; border-left: 4px solid #3c91a8; padding: .85rem 1rem; border-radius: 4px; }
.photo-text-status { min-height: 1.4em; color: #8a3026; font-weight: 600; }
</style>
<main class="photo-text-page">
  <h1>Photo to Text</h1>
  <div class="photo-text-card">
    <p>Take a photo of a receipt, label, invoice, or note and turn its visible text into editable text. Your image stays in this browser unless you choose AI transcription.</p>
    <p><strong>Configured AI model:</strong> <span id="configuredModel"><?php echo htmlspecialchars($configuredModel, ENT_QUOTES, 'UTF-8'); ?></span> <small>(images are sent in high-detail mode; reasoning: <?php echo htmlspecialchars($configuredReasoning, ENT_QUOTES, 'UTF-8'); ?>)</small></p>
    <p class="photo-text-note"><strong>Two test paths:</strong> use <em>Transcribe with AI</em> after configuring an API key, or use <em>Copy Cursor/Codex prompt</em>, then attach the same image in your Cursor or Codex chat and paste the prompt.</p>
    <input id="photoInput" type="file" accept="image/jpeg,image/png,image/webp" capture="environment">
    <img id="photoPreview" class="photo-preview" alt="Selected photo preview">
    <div class="photo-text-actions">
      <button id="transcribeButton" class="photo-text-button" type="button">Transcribe with AI</button>
      <button id="handoffButton" class="photo-text-button secondary" type="button">Copy Cursor/Codex prompt</button>
      <button id="copyTextButton" class="photo-text-button secondary" type="button">Copy text</button>
    </div>
    <p id="status" class="photo-text-status" role="status" aria-live="polite"></p>
    <label for="result"><strong>Transcribed text</strong></label>
    <textarea id="result" class="photo-text-result" placeholder="Your text will appear here. You can also paste the response from Cursor or Codex."></textarea>
  </div>
</main>
<script>
(() => {
  const input = document.getElementById('photoInput');
  const preview = document.getElementById('photoPreview');
  const status = document.getElementById('status');
  const result = document.getElementById('result');
  const transcribe = document.getElementById('transcribeButton');
  const prompt = 'Please transcribe every piece of visible text in this image. Preserve line breaks, spelling, numbers, and punctuation. Return only the transcription, with no description or commentary.';
  input.addEventListener('change', () => {
    const file = input.files[0];
    status.textContent = '';
    if (!file) { preview.style.display = 'none'; return; }
    preview.src = URL.createObjectURL(file);
    preview.style.display = 'block';
  });
  document.getElementById('handoffButton').addEventListener('click', async () => {
    await navigator.clipboard.writeText(prompt);
    status.style.color = '#176338'; status.textContent = 'Prompt copied. Attach this same photo in Cursor or Codex, paste the prompt, and copy its response here.';
  });
  document.getElementById('copyTextButton').addEventListener('click', async () => {
    await navigator.clipboard.writeText(result.value);
    status.style.color = '#176338'; status.textContent = 'Text copied.';
  });
  transcribe.addEventListener('click', async () => {
    if (!input.files[0]) { status.style.color = '#8a3026'; status.textContent = 'Take or choose a photo first.'; return; }
    const data = new FormData(); data.append('photo', input.files[0]);
    data.append('csrf_token', document.querySelector('meta[name="csrf-token"]').content);
    transcribe.disabled = true; status.style.color = '#12475a'; status.textContent = 'Reading the photo…';
    try {
      const response = await fetch(window.location.href, { method: 'POST', body: data, headers: { 'Accept': 'application/json' } });
      const raw = await response.text();
      let json;
      try { json = JSON.parse(raw); }
      catch (_) { throw new Error(`Server returned an invalid response (${response.status}). ${raw.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 220)}`); }
      if (!json.success) throw new Error(json.error || 'Transcription failed.');
      result.value = json.text;
      const usage = json.usage || {};
      status.style.color = '#176338';
      status.textContent = `Transcription complete with ${json.model || 'the configured model'} (${usage.input_tokens || 0} input / ${usage.output_tokens || 0} output tokens). Review before using it.`;
    } catch (error) {
      status.style.color = '#8a3026'; status.textContent = error.message || 'Transcription failed.';
    } finally { transcribe.disabled = false; }
  });
})();
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
