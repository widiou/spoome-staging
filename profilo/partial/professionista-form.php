<div class="mb-3">
    <label>Foto profilo <small class="text-muted">(max 800x800, 1:1)</small></label><br>
    <input type="file" name="foto_profilo_raw" id="foto_profilo_raw" accept="image/*" class="form-control">
    <canvas id="preview_profilo" class="preview-crop d-none mt-3 border" style="width: 100%; max-width: 300px;"></canvas>
    <input type="hidden" name="foto_profilo" id="foto_profilo">
</div>

<div class="mb-3">
    <label>Copertina <small class="text-muted">(max 1920x1080, 16:9)</small></label><br>
    <input type="file" name="immagine_cover_raw" id="immagine_cover_raw" accept="image/*" class="form-control">
    <canvas id="preview_cover" class="preview-crop d-none mt-3 border" style="width: 100%; max-width: 640px;"></canvas>
    <input type="hidden" name="immagine_cover" id="immagine_cover">
</div>


<div class="mb-3">
    <label>Qualifica *</label>
    <input type="text" name="qualifica" class="form-control <?= !$profilo['qualifica'] ? 'is-invalid' : '' ?>" required value="<?= htmlspecialchars($profilo['qualifica']) ?>">
    <div class="invalid-feedback">Inserisci una qualifica.</div>
</div>

<div class="mb-3">
    <label>Settore *</label>
    <input type="text" name="settore" class="form-control <?= !$profilo['settore'] ? 'is-invalid' : '' ?>" required value="<?= htmlspecialchars($profilo['settore']) ?>">
    <div class="invalid-feedback">Inserisci un settore.</div>
</div>

<div class="mb-3">
    <label>Biografia</label>
    <textarea name="bio" class="form-control"><?= htmlspecialchars($profilo['descrizione']) ?></textarea>
</div>

<div class="mb-3">
    <label>Esperienza</label>
    <textarea name="esperienza" class="form-control"><?= htmlspecialchars($profilo['esperienza']) ?></textarea>
</div>

<div class="mb-3">
    <label>Certificazioni</label>
    <input type="text" name="certificazioni" class="form-control" value="<?= htmlspecialchars($profilo['certificazioni']) ?>">
</div>

<div class="mb-3">
    <label>LinkedIn</label>
    <input type="url" name="linkedin" class="form-control" value="<?= htmlspecialchars($profilo['linkedin']) ?>">
</div>

<div class="mb-3">
    <label>Sito Web</label>
    <input type="url" name="sito_web" class="form-control" value="<?= htmlspecialchars($profilo['sito_web']) ?>">
</div>
