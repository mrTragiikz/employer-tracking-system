<?php
/**
 * admin/components/footer/footer.php
 *
 * Closes </main>, .admin-content, .admin-shell, and </body></html> opened by
 * header.php, then loads component JS. Last line of every admin page:
 *
 *   require dirname(__DIR__) . '/components/footer/footer.php';
 */

declare(strict_types=1);
?>
    </main><!-- .admin-main -->

    <footer class="admin-footer">
      <span>&copy; <?= e(date('Y')) ?> <?= e(APP_NAME) ?>. All rights reserved.</span>
      <span class="admin-footer__made">Made with <span class="admin-footer__heart">&#10084;</span> for better field management.</span>
    </footer>
  </div><!-- .admin-content -->
</div><!-- .admin-shell -->

<script src="<?= e(asset_url(APP_URL . '/admin/components/sidebar/js/sidebar.js')) ?>"></script>
<script src="<?= e(asset_url(APP_URL . '/admin/components/header/js/header.js')) ?>"></script>
<script src="<?= e(asset_url(APP_URL . '/admin/components/footer/js/footer.js')) ?>"></script>
<script src="<?= e(asset_url(APP_URL . '/admin/components/confirm-modal/js/confirm-modal.js')) ?>"></script>
</body>
</html>
