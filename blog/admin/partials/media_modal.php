<dialog class="picker" data-picker aria-label="Choose an image">
  <div class="picker__head">
    <strong>Choose an image</strong>
    <input type="search" placeholder="Search" data-pickq>
    <label class="btn btn--small btn--primary">Upload new<input type="file" accept="image/jpeg,image/png,image/webp,image/gif" multiple hidden data-pickupload></label>
    <button type="button" class="btn btn--small btn--ghost" data-pickclose>Close</button>
  </div>
  <div class="upload-status" data-pickstatus></div>
  <div class="picker__grid" data-pickgrid></div>
  <div class="picker__foot"><button type="button" class="btn btn--small btn--ghost" data-pickmore hidden>Show more</button></div>
</dialog>
<script>window.ADMIN={media:<?= json_encode(aurl('media', ['format' => 'json'])) ?>,upload:<?= json_encode(aurl('media', ['ajax' => 'upload'])) ?>,csrf:<?= json_encode(Auth::csrfToken()) ?>};</script>
