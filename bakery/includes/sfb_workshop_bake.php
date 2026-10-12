<?php
/**
 * Timings, stage photos, and questions for the Intensive loaf.
 * Expects $workshopBatch, $workshopTurns, $workshopTemps, $workshopPhotos,
 * $workshopThreads, and $view from sfb_intensive.php.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

$workshopEditable = (string)($workshopBatch['status'] ?? '') === 'in_progress';
$workshopStep = (string)($view['step'] ?? 'formula');
$phaseForStep = [
    'formula' => 'mix',
    'mix' => 'mix',
    'bulk' => 'development',
    'shape' => 'shape',
    'bake' => 'bake',
    'done' => 'final',
];
$workshopPhase = $phaseForStep[$workshopStep] ?? 'mix';
$photosByPhase = [];
foreach ($workshopPhotos as $photo) {
    $photosByPhase[(string)$photo['phase']][] = $photo;
}
$photoPhases = ['mix', 'development', 'shape', 'bake', 'final'];
$timeBits = [];
if ($workshopBatch['mix_minutes'] !== null && $workshopBatch['mix_minutes'] !== '') {
    $timeBits[] = bakery_t('sfb.phase_mix') . ' ' . (int)$workshopBatch['mix_minutes'] . ' min';
}
if (!empty($workshopBatch['bulk_started_at'])) {
    $timeBits[] = bakery_t('sfb.bulk_started') . ' ' . date('D g:ia', strtotime((string)$workshopBatch['bulk_started_at']));
}
if (!empty($workshopBatch['shaped_at'])) {
    $timeBits[] = bakery_t('sfb.shaped_at') . ' ' . date('D g:ia', strtotime((string)$workshopBatch['shaped_at']));
}
if (!empty($workshopBatch['bake_started_at'])) {
    $timeBits[] = bakery_t('sfb.bake_started') . ' ' . date('D g:ia', strtotime((string)$workshopBatch['bake_started_at']));
}
?>

<?php if ($timeBits && !in_array($workshopStep, ['mix', 'bulk', 'shape', 'bake'], true)): ?>
  <section class="card">
    <div class="card-header"><h2><?php bakery_te('sfb.intensive_times'); ?></h2></div>
    <div class="card-body">
      <p style="margin:0;"><?php echo htmlspecialchars(implode(' · ', $timeBits), ENT_QUOTES, 'UTF-8'); ?></p>
    </div>
  </section>
<?php endif; ?>

<?php if ($workshopStep === 'mix'): ?>
  <section class="card">
    <div class="card-header"><h2><?php bakery_te('sfb.phase_mix'); ?></h2></div>
    <div class="card-body">
      <form method="post" class="ws-fields">
        <?php echo bakery_csrf_field(); ?>
        <input type="hidden" name="action" value="save_mix">
        <label style="display:block;margin:0 0 8px;">
          <span class="muted"><?php bakery_te('sfb.mix_minutes'); ?></span>
          <input type="number" name="mix_minutes" min="0" max="600" step="1" value="<?php echo $workshopBatch['mix_minutes'] !== null ? (int)$workshopBatch['mix_minutes'] : ''; ?>" <?php echo $workshopEditable ? '' : 'disabled'; ?> style="display:block;width:8rem;margin-top:4px;padding:10px;">
        </label>
        <label style="display:block;margin:0 0 8px;">
          <span class="muted"><?php bakery_te('sfb.mix_speed'); ?></span>
          <input type="text" name="mix_speed" maxlength="50" value="<?php echo htmlspecialchars((string)($workshopBatch['mix_speed'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" <?php echo $workshopEditable ? '' : 'disabled'; ?> style="display:block;width:100%;margin-top:4px;padding:10px;">
        </label>
        <label style="display:block;margin:0 0 8px;">
          <span class="muted"><?php bakery_te('sfb.mix_finished_at'); ?></span>
          <input type="datetime-local" name="mix_completed_at" value="<?php echo htmlspecialchars(bakery_sfb_datetime_local_value($workshopBatch['mix_completed_at']), ENT_QUOTES, 'UTF-8'); ?>" <?php echo $workshopEditable ? '' : 'disabled'; ?> style="display:block;width:100%;margin-top:4px;padding:10px;">
        </label>
        <label style="display:block;margin:0 0 8px;">
          <span class="muted"><?php bakery_te('sfb.mix_notes'); ?></span>
          <textarea name="mix_notes" rows="2" <?php echo $workshopEditable ? '' : 'disabled'; ?> style="display:block;width:100%;margin-top:4px;padding:10px;"><?php echo htmlspecialchars((string)($workshopBatch['mix_notes'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
        </label>
        <?php if ($workshopEditable): ?>
          <button type="submit" class="btn btn-secondary btn-block"><?php bakery_te('sfb.save_phase'); ?></button>
        <?php endif; ?>
      </form>
    </div>
  </section>
<?php elseif ($workshopStep === 'bulk'): ?>
  <section class="card">
    <div class="card-header"><h2><?php bakery_te('sfb.phase_development'); ?></h2></div>
    <div class="card-body">
      <form method="post" class="ws-fields">
        <?php echo bakery_csrf_field(); ?>
        <input type="hidden" name="action" value="save_development">
        <label style="display:block;margin:0 0 8px;">
          <span class="muted"><?php bakery_te('sfb.bulk_started'); ?></span>
          <input type="datetime-local" name="bulk_started_at" value="<?php echo htmlspecialchars(bakery_sfb_datetime_local_value($workshopBatch['bulk_started_at']), ENT_QUOTES, 'UTF-8'); ?>" <?php echo $workshopEditable ? '' : 'disabled'; ?> style="display:block;width:100%;margin-top:4px;padding:10px;">
        </label>
        <label style="display:block;margin:0 0 8px;">
          <span class="muted"><?php bakery_te('sfb.bulk_ended'); ?></span>
          <input type="datetime-local" name="bulk_ended_at" value="<?php echo htmlspecialchars(bakery_sfb_datetime_local_value($workshopBatch['bulk_ended_at']), ENT_QUOTES, 'UTF-8'); ?>" <?php echo $workshopEditable ? '' : 'disabled'; ?> style="display:block;width:100%;margin-top:4px;padding:10px;">
        </label>
        <?php if ($workshopEditable): ?>
          <button type="submit" class="btn btn-secondary btn-block"><?php bakery_te('sfb.save_phase'); ?></button>
        <?php endif; ?>
      </form>
      <?php foreach ($workshopTurns as $turn): ?>
        <p style="margin:10px 0 0;">
          <?php echo htmlspecialchars(bakery_sfb_turn_type_label($turn['turn_type']), ENT_QUOTES, 'UTF-8'); ?>
          · <?php echo htmlspecialchars(date('D g:ia', strtotime((string)$turn['occurred_at'])), ENT_QUOTES, 'UTF-8'); ?>
          <?php if ($turn['dough_temp_f'] !== null): ?> · <?php echo (float)$turn['dough_temp_f']; ?>°F<?php endif; ?>
          <?php if (!empty($turn['notes'])): ?> · <?php echo htmlspecialchars((string)$turn['notes'], ENT_QUOTES, 'UTF-8'); ?><?php endif; ?>
          <?php if ($workshopEditable): ?>
            <form method="post" style="display:inline;">
              <?php echo bakery_csrf_field(); ?>
              <input type="hidden" name="action" value="delete_turn">
              <input type="hidden" name="turn_id" value="<?php echo (int)$turn['id']; ?>">
              <button type="submit" class="btn-link">✕</button>
            </form>
          <?php endif; ?>
        </p>
      <?php endforeach; ?>
      <?php if ($workshopEditable): ?>
        <form method="post" class="ws-fields" style="margin-top:12px;">
          <?php echo bakery_csrf_field(); ?>
          <input type="hidden" name="action" value="add_turn">
          <label style="display:block;margin:0 0 8px;">
            <span class="muted"><?php bakery_te('sfb.turn_type'); ?></span>
            <select name="turn_type" style="display:block;width:100%;margin-top:4px;padding:10px;">
              <?php foreach (bakery_sfb_turn_types() as $key => $label): ?>
                <option value="<?php echo htmlspecialchars((string)$key, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars((string)$label, ENT_QUOTES, 'UTF-8'); ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label style="display:block;margin:0 0 8px;">
            <span class="muted"><?php bakery_te('sfb.turn_at'); ?></span>
            <input type="datetime-local" name="occurred_at" value="<?php echo htmlspecialchars(bakery_sfb_now_local_value(), ENT_QUOTES, 'UTF-8'); ?>" style="display:block;width:100%;margin-top:4px;padding:10px;">
          </label>
          <label style="display:block;margin:0 0 8px;">
            <span class="muted"><?php bakery_te('sfb.dough_temp_optional'); ?></span>
            <input type="number" name="dough_temp_f" min="0" max="150" step="0.1" style="display:block;width:8rem;margin-top:4px;padding:10px;">
          </label>
          <label style="display:block;margin:0 0 8px;">
            <span class="muted"><?php bakery_te('sfb.notes'); ?></span>
            <input type="text" name="notes" maxlength="255" style="display:block;width:100%;margin-top:4px;padding:10px;">
          </label>
          <button type="submit" class="btn btn-secondary btn-block"><?php bakery_te('sfb.log_turn'); ?></button>
        </form>
      <?php endif; ?>
    </div>
  </section>
<?php elseif ($workshopStep === 'shape'): ?>
  <section class="card">
    <div class="card-header"><h2><?php bakery_te('sfb.phase_shape'); ?></h2></div>
    <div class="card-body">
      <form method="post" class="ws-fields">
        <?php echo bakery_csrf_field(); ?>
        <input type="hidden" name="action" value="save_shape">
        <label style="display:block;margin:0 0 8px;">
          <span class="muted"><?php bakery_te('sfb.shaped_at'); ?></span>
          <input type="datetime-local" name="shaped_at" value="<?php echo htmlspecialchars(bakery_sfb_datetime_local_value($workshopBatch['shaped_at']), ENT_QUOTES, 'UTF-8'); ?>" <?php echo $workshopEditable ? '' : 'disabled'; ?> style="display:block;width:100%;margin-top:4px;padding:10px;">
        </label>
        <label style="display:block;margin:0 0 8px;">
          <span class="muted"><?php bakery_te('sfb.shape_notes'); ?></span>
          <textarea name="shape_notes" rows="2" <?php echo $workshopEditable ? '' : 'disabled'; ?> style="display:block;width:100%;margin-top:4px;padding:10px;"><?php echo htmlspecialchars((string)($workshopBatch['shape_notes'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
        </label>
        <?php if ($workshopEditable): ?>
          <button type="submit" class="btn btn-secondary btn-block"><?php bakery_te('sfb.save_phase'); ?></button>
        <?php endif; ?>
      </form>
    </div>
  </section>
<?php elseif ($workshopStep === 'bake' || $workshopStep === 'done'): ?>
  <section class="card">
    <div class="card-header"><h2><?php bakery_te('sfb.phase_bake'); ?></h2></div>
    <div class="card-body">
      <form method="post" class="ws-fields">
        <?php echo bakery_csrf_field(); ?>
        <input type="hidden" name="action" value="save_bake">
        <label style="display:block;margin:0 0 8px;">
          <span class="muted"><?php bakery_te('sfb.bake_started'); ?></span>
          <input type="datetime-local" name="bake_started_at" value="<?php echo htmlspecialchars(bakery_sfb_datetime_local_value($workshopBatch['bake_started_at']), ENT_QUOTES, 'UTF-8'); ?>" <?php echo $workshopEditable ? '' : 'disabled'; ?> style="display:block;width:100%;margin-top:4px;padding:10px;">
        </label>
        <label style="display:block;margin:0 0 8px;">
          <span class="muted"><?php bakery_te('sfb.bake_ended'); ?></span>
          <input type="datetime-local" name="bake_ended_at" value="<?php echo htmlspecialchars(bakery_sfb_datetime_local_value($workshopBatch['bake_ended_at']), ENT_QUOTES, 'UTF-8'); ?>" <?php echo $workshopEditable ? '' : 'disabled'; ?> style="display:block;width:100%;margin-top:4px;padding:10px;">
        </label>
        <label style="display:block;margin:0 0 8px;">
          <span class="muted"><?php bakery_te('sfb.oven_temp'); ?></span>
          <input type="number" name="oven_temp_f" min="0" max="600" step="1" value="<?php echo $workshopBatch['oven_temp_f'] !== null ? (float)$workshopBatch['oven_temp_f'] : ''; ?>" <?php echo $workshopEditable ? '' : 'disabled'; ?> style="display:block;width:8rem;margin-top:4px;padding:10px;">
        </label>
        <label style="display:block;margin:0 0 8px;">
          <span class="muted"><?php bakery_te('sfb.bake_notes'); ?></span>
          <textarea name="bake_notes" rows="2" <?php echo $workshopEditable ? '' : 'disabled'; ?> style="display:block;width:100%;margin-top:4px;padding:10px;"><?php echo htmlspecialchars((string)($workshopBatch['bake_notes'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
        </label>
        <?php if ($workshopEditable): ?>
          <button type="submit" class="btn btn-secondary btn-block"><?php bakery_te('sfb.save_phase'); ?></button>
        <?php endif; ?>
      </form>
    </div>
  </section>
<?php endif; ?>

<section class="card">
  <div class="card-header"><h2><?php bakery_te('sfb.photos'); ?></h2></div>
  <div class="card-body">
    <?php if (!$workshopPhotos): ?>
      <p class="muted" style="margin-top:0;"><?php bakery_te('sfb.no_photos'); ?></p>
    <?php else: ?>
      <?php foreach ($photoPhases as $photoPhase): ?>
        <?php if (empty($photosByPhase[$photoPhase])) { continue; } ?>
        <p class="muted" style="margin:8px 0 4px;"><?php echo htmlspecialchars(bakery_sfb_phase_label($photoPhase), ENT_QUOTES, 'UTF-8'); ?></p>
        <div class="sfb-photos">
          <?php foreach ($photosByPhase[$photoPhase] as $photo): ?>
            <div class="sfb-photo-wrap">
              <img class="sfb-photo" src="<?php echo htmlspecialchars(bakery_sfb_photo_url($photo['file_path']), ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars((string)($photo['caption'] ?? bakery_sfb_phase_label($photoPhase)), ENT_QUOTES, 'UTF-8'); ?>">
              <?php if (!empty($photo['caption'])): ?>
                <p class="sfb-caption"><?php echo htmlspecialchars((string)$photo['caption'], ENT_QUOTES, 'UTF-8'); ?></p>
              <?php endif; ?>
              <?php if ($workshopEditable): ?>
                <form method="post" class="ws-inline">
                  <?php echo bakery_csrf_field(); ?>
                  <input type="hidden" name="action" value="delete_photo">
                  <input type="hidden" name="photo_id" value="<?php echo (int)$photo['id']; ?>">
                  <button type="submit" class="btn-link"><?php bakery_te('sfb.delete'); ?></button>
                </form>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
    <?php if ($workshopEditable): ?>
      <form method="post" class="ws-fields" enctype="multipart/form-data" style="margin-top:12px;">
        <?php echo bakery_csrf_field(); ?>
        <input type="hidden" name="action" value="upload_photo">
        <label style="display:block;margin:0 0 8px;">
          <span class="muted"><?php bakery_te('sfb.phase'); ?></span>
          <select name="phase" style="display:block;width:100%;margin-top:4px;padding:10px;">
            <?php foreach ($photoPhases as $photoPhase): ?>
              <option value="<?php echo htmlspecialchars($photoPhase, ENT_QUOTES, 'UTF-8'); ?>"<?php echo $photoPhase === $workshopPhase ? ' selected' : ''; ?>><?php echo htmlspecialchars(bakery_sfb_phase_label($photoPhase), ENT_QUOTES, 'UTF-8'); ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label style="display:block;margin:0 0 8px;">
          <span class="muted"><?php bakery_te('sfb.caption'); ?></span>
          <input type="text" name="caption" maxlength="255" style="display:block;width:100%;margin-top:4px;padding:10px;">
        </label>
        <input type="file" name="photo" accept="image/*" capture="environment" required>
        <button type="submit" class="btn btn-block" style="margin-top:10px;"><?php bakery_te('sfb.add_photo'); ?></button>
      </form>
    <?php endif; ?>
  </div>
</section>

<?php if ($workshopStep !== 'formula'): ?>
  <section class="card">
    <div class="card-header"><h2><?php bakery_te('sfb.dough_temps'); ?></h2></div>
    <div class="card-body">
      <?php if (!$workshopTemps): ?>
        <p class="muted" style="margin-top:0;"><?php bakery_te('sfb.no_temps'); ?></p>
      <?php else: ?>
        <?php foreach ($workshopTemps as $tempRow): ?>
          <p style="margin:0 0 6px;">
            <?php echo htmlspecialchars(bakery_sfb_phase_label($tempRow['phase']), ENT_QUOTES, 'UTF-8'); ?>
            · <?php echo (float)$tempRow['temp_f']; ?>°F
            <?php if ($workshopEditable): ?>
              <form method="post" style="display:inline;">
                <?php echo bakery_csrf_field(); ?>
                <input type="hidden" name="action" value="delete_temp">
                <input type="hidden" name="temp_id" value="<?php echo (int)$tempRow['id']; ?>">
                <button type="submit" class="btn-link">✕</button>
              </form>
            <?php endif; ?>
          </p>
        <?php endforeach; ?>
      <?php endif; ?>
      <?php if ($workshopEditable): ?>
        <form method="post" class="ws-fields" style="margin-top:8px;">
          <?php echo bakery_csrf_field(); ?>
          <input type="hidden" name="action" value="add_temp">
          <input type="hidden" name="phase" value="<?php echo htmlspecialchars($workshopPhase === 'final' ? 'bake' : $workshopPhase, ENT_QUOTES, 'UTF-8'); ?>">
          <label style="display:block;margin:0 0 8px;">
            <span class="muted"><?php bakery_te('sfb.temp_f'); ?></span>
            <input type="number" name="temp_f" min="0" max="150" step="0.1" required style="display:block;width:8rem;margin-top:4px;padding:10px;">
          </label>
          <button type="submit" class="btn btn-secondary btn-block"><?php bakery_te('sfb.log_temp'); ?></button>
        </form>
      <?php endif; ?>
    </div>
  </section>
<?php endif; ?>

<section class="card" id="sfb-discussion">
  <div class="card-header"><h2><?php bakery_te('sfb.discussion'); ?></h2></div>
  <div class="card-body">
    <p class="muted" style="margin-top:0;"><?php bakery_te('sfb.discussion_hint'); ?></p>
    <?php if (empty($workshopThreads['roots'])): ?>
      <p class="muted"><?php bakery_te('sfb.discussion_empty'); ?></p>
    <?php else: ?>
      <?php foreach ($workshopThreads['roots'] as $message): ?>
        <?php $messageId = (int)$message['id']; ?>
        <article style="margin:0 0 12px;">
          <p style="margin:0;">
            <strong><?php echo htmlspecialchars((string)$message['author_name'], ENT_QUOTES, 'UTF-8'); ?></strong>
            <?php if ((string)$message['message_type'] === 'question'): ?>
              · <?php bakery_te((int)$message['is_resolved'] === 1 ? 'sfb.answered' : 'sfb.question'); ?>
            <?php else: ?>
              · <?php bakery_te('sfb.comment'); ?>
            <?php endif; ?>
            <?php if (!empty($message['phase'])): ?>
              · <?php echo htmlspecialchars(bakery_sfb_phase_label($message['phase']), ENT_QUOTES, 'UTF-8'); ?>
            <?php endif; ?>
          </p>
          <p style="margin:4px 0 0;"><?php echo nl2br(htmlspecialchars((string)$message['body'], ENT_QUOTES, 'UTF-8')); ?></p>
        </article>
        <?php foreach ($workshopThreads['replies'][$messageId] ?? [] as $reply): ?>
          <article style="margin:0 0 12px 16px;">
            <p style="margin:0;"><strong><?php echo htmlspecialchars((string)$reply['author_name'], ENT_QUOTES, 'UTF-8'); ?></strong></p>
            <p style="margin:4px 0 0;"><?php echo nl2br(htmlspecialchars((string)$reply['body'], ENT_QUOTES, 'UTF-8')); ?></p>
          </article>
        <?php endforeach; ?>
      <?php endforeach; ?>
    <?php endif; ?>
    <form method="post" class="ws-fields" style="margin-top:8px;">
      <?php echo bakery_csrf_field(); ?>
      <input type="hidden" name="action" value="add_discussion">
      <input type="hidden" name="phase" value="<?php echo htmlspecialchars($workshopPhase === 'final' ? 'bake' : $workshopPhase, ENT_QUOTES, 'UTF-8'); ?>">
      <label style="display:block;margin:0 0 8px;">
        <span class="muted"><?php bakery_te('sfb.message_type'); ?></span>
        <select name="message_type" style="display:block;width:100%;margin-top:4px;padding:10px;">
          <option value="comment"><?php bakery_te('sfb.comment'); ?></option>
          <option value="question"><?php bakery_te('sfb.question'); ?></option>
        </select>
      </label>
      <label style="display:block;margin:0 0 8px;">
        <span class="muted"><?php bakery_te('sfb.message'); ?></span>
        <textarea name="body" rows="3" maxlength="4000" required style="display:block;width:100%;margin-top:4px;padding:10px;"></textarea>
      </label>
      <button type="submit" class="btn btn-secondary btn-block"><?php bakery_te('sfb.share_message'); ?></button>
    </form>
  </div>
</section>
