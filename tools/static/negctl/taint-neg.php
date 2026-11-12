<?php
function neg_html() {
    echo $_GET['x'];
}
function neg_sql() {
    global $wpdb;
    $wpdb->query("DELETE FROM t WHERE a = '" . $_POST['a'] . "'");
}
function neg_ok() {
    echo esc_html($_GET['y']);
}
