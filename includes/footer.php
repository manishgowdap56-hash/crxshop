<?php
/* Shared page footer: closes main document and loads global + page JS. */
?>
</main>
</div><!-- /.dash-shell -->

<?php $footerAppJsFile = __DIR__ . '/../assets/js/app.js'; $footerAppJsVer = is_file($footerAppJsFile) ? (string)filemtime($footerAppJsFile) : '1'; ?>
<script src="<?= e(asset('js/app.js')) ?>?v=<?= e($footerAppJsVer) ?>"></script>
<script src="<?= e(asset('js/page-entry.js')) ?>?v=<?= e((string)(is_file(__DIR__ . '/../assets/js/page-entry.js') ? filemtime(__DIR__ . '/../assets/js/page-entry.js') : 1)) ?>"></script>
<?php $clockJsFile = __DIR__ . '/../assets/js/header-clock.js'; ?>
<script src="<?= e(asset('js/header-clock.js')) ?>?v=<?= e(is_file($clockJsFile) ? (string)filemtime($clockJsFile) : '1') ?>"></script>
<?php if (function_exists('is_logged_in') && is_logged_in()): ?>
<script src="<?= e(asset('js/notices.js')) ?>?v=<?= e((string)(is_file(__DIR__ . '/../assets/js/notices.js') ? filemtime(__DIR__ . '/../assets/js/notices.js') : 1)) ?>"></script>
<?php endif; ?>
<?php foreach (($page_js ?? []) as $js): ?>
<?php $footerJsFile = __DIR__ . '/../assets/js/' . ltrim($js, '/'); $footerJsVer = is_file($footerJsFile) ? (string)filemtime($footerJsFile) : '1'; ?>
<script src="<?= e(asset('js/' . $js)) ?>?v=<?= e($footerJsVer) ?>"></script>
<?php endforeach; ?>
</body>
</html>
