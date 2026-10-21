<?php
/**
 * Mamabe admin layout - bottom partial. Prints the page script, then closes the DB connection
 * (index_adv.php with $connect=0) and flushes the page buffered in header.php.
 */
?>
    <?php if (isset($page_js) && $page_js !== ''): ?>
    <script src="<?php echo $page_js; ?>?v=<?php echo time(); ?>"></script>
    <?php endif; ?>
</body>
</html>
<?php
$connect = 0;
include(dirname(__FILE__) . '/../../common/index_adv.php');
ob_end_flush(); // matches the ob_start() in header.php - nothing is actually sent until here
