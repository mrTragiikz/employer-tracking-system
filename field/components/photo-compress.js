/**
 * field/components/photo-compress.js
 *
 * Compresses a camera-captured photo down to a target file size (~70 KB)
 * ENTIRELY IN THE BROWSER, before it's ever uploaded - no server extension
 * (GD/Imagick) required at all, which matters because this app's actual
 * live cPanel host cannot be guaranteed to have either installed, while
 * every phone browser Track's field app already targets has supported
 * <canvas>.toBlob() JPEG re-encoding for years. This also cuts the
 * employee's own mobile data usage on every check-in/check-out/visit
 * photo, which matters more here than it would on desktop.
 *
 * Shared by field/checkinout/js/checkinout.js and field/visit/js/visit.js -
 * both wire the SAME <input type="file"> "change" flow (grab the file,
 * preview it, gate the submit button), so this file owns just the one new
 * step both need: replace the input's raw camera file with a compressed
 * version before the preview/gating code ever sees it.
 *
 * Usage: TrackPhotoCompress.compress(inputEl [, targetBytes]) returns a
 * Promise that resolves once inputEl.files[0] has been replaced with the
 * compressed version (same filename, image/jpeg). targetBytes is optional -
 * it defaults to DEFAULT_TARGET_BYTES (~70 KB, the field-app camera photos);
 * the admin employee form passes a smaller value for headshots/ID scans.
 * Resolves even on failure (falls back to the ORIGINAL file untouched) - a
 * compression bug must never be the reason a field employee can't check
 * in/out or log a visit at all.
 */
(function () {
  'use strict';

  var DEFAULT_TARGET_BYTES = 70 * 1024; // ~70 KB per photo (field-app default)
  // Progressively smaller max dimensions tried alongside quality - a huge
  // modern phone photo (e.g. 4080x3072, seen in this app's own real
  // uploads) can't hit 70 KB at full resolution no matter how low the JPEG
  // quality goes without visible blocking artifacts; shrinking the
  // dimensions first gets there while staying legible (the photo only
  // needs to be clear enough for an admin to verify - an odometer reading,
  // a shop signboard - not print quality).
  var MAX_DIMENSIONS = [1600, 1280, 1024, 800, 640];
  var QUALITIES = [0.8, 0.65, 0.5, 0.35, 0.25, 0.15];

  /** Draws `file` onto a canvas capped at maxDim on its longer side, returns the canvas. */
  function drawToCanvas(img, maxDim) {
    var w = img.naturalWidth || img.width;
    var h = img.naturalHeight || img.height;
    var scale = Math.min(1, maxDim / Math.max(w, h));
    var canvas = document.createElement('canvas');
    canvas.width = Math.max(1, Math.round(w * scale));
    canvas.height = Math.max(1, Math.round(h * scale));
    var ctx = canvas.getContext('2d');
    ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
    return canvas;
  }

  function canvasToBlob(canvas, quality) {
    return new Promise(function (resolve) {
      canvas.toBlob(function (blob) { resolve(blob); }, 'image/jpeg', quality);
    });
  }

  function loadImage(file) {
    return new Promise(function (resolve, reject) {
      var url = URL.createObjectURL(file);
      var img = new Image();
      img.onload = function () { URL.revokeObjectURL(url); resolve(img); };
      img.onerror = function () { URL.revokeObjectURL(url); reject(new Error('could not decode image')); };
      img.src = url;
    });
  }

  /**
   * Tries every (dimension, quality) combination, LARGEST/HIGHEST first,
   * and keeps the first result at or under targetBytes. If NOTHING gets
   * under target (a pathological case), falls back to the smallest/lowest
   * attempt anyway - a slightly-over-target photo is still far smaller
   * than the untouched original, and must never block the action outright.
   */
  function findSmallestBlob(img, targetBytes) {
    var attempts = [];
    MAX_DIMENSIONS.forEach(function (dim) {
      QUALITIES.forEach(function (q) { attempts.push({ dim: dim, q: q }); });
    });

    var i = 0;
    var smallestSoFar = null;

    function tryNext() {
      if (i >= attempts.length) {
        return Promise.resolve(smallestSoFar);
      }
      var attempt = attempts[i++];
      var canvas = drawToCanvas(img, attempt.dim);
      return canvasToBlob(canvas, attempt.q).then(function (blob) {
        if (!blob) return tryNext();
        if (!smallestSoFar || blob.size < smallestSoFar.size) smallestSoFar = blob;
        if (blob.size <= targetBytes) return blob;
        return tryNext();
      });
    }

    return tryNext();
  }

  /**
   * Replaces inputEl.files[0] with a compressed version. Resolves always
   * (never rejects) - on any failure the ORIGINAL file is left in place
   * untouched, so a compression bug degrades to "upload is bigger than
   * ideal" rather than "the employee cannot submit at all".
   *
   * @param {HTMLInputElement} inputEl     the <input type="file">
   * @param {number} [targetBytes]         max size to aim for; defaults to
   *                                       DEFAULT_TARGET_BYTES (~70 KB)
   */
  function compress(inputEl, targetBytes) {
    var target = (typeof targetBytes === 'number' && targetBytes > 0)
      ? targetBytes : DEFAULT_TARGET_BYTES;

    var file = inputEl.files && inputEl.files[0];
    if (!file) return Promise.resolve();
    if (!file.type || file.type.indexOf('image/') !== 0) return Promise.resolve();

    // Already small enough (e.g. a low-res front camera, or a phone that
    // already compresses aggressively) - skip the work entirely.
    if (file.size <= target) return Promise.resolve();

    if (typeof HTMLCanvasElement === 'undefined' || !HTMLCanvasElement.prototype.toBlob) {
      return Promise.resolve(); // no canvas support - upload the original, server-side size cap is the safety net
    }

    return loadImage(file)
      .then(function (img) { return findSmallestBlob(img, target); })
      .then(function (blob) {
        if (!blob) return; // could not produce anything - keep the original
        var originalName = file.name || 'photo.jpg';
        var jpegName = originalName.replace(/\.\w+$/, '') + '.jpg';
        var compressedFile = new File([blob], jpegName, { type: 'image/jpeg', lastModified: Date.now() });

        // DataTransfer is the standard way to replace a file <input>'s
        // FileList programmatically - a FileList itself can't be
        // constructed or mutated directly.
        var dt = new DataTransfer();
        dt.items.add(compressedFile);
        inputEl.files = dt.files;
      })
      .catch(function () {
        // Decoding/compression failed for any reason - leave the original
        // file exactly as the camera produced it. UPLOAD_MAX_BYTES on the
        // server is the real backstop either way.
      });
  }

  window.TrackPhotoCompress = { compress: compress };
})();
