<?php include 'db_connect.php'; session_start(); ?>

<h2>Forum</h2>

<?php
// Show all forum content-type items
$result = $conn->query("SELECT * FROM contents WHERE type = 'forum'");
while ($content = $result->fetch_assoc()) {
    echo "<h3>{$content['title']}</h3>";

    $posts = $conn->query("SELECT p.body, u.username, p.posted_at FROM forum_posts p JOIN users u ON u.user_id = p.user_id WHERE content_id = {$content['content_id']} AND parent_post_id IS NULL ORDER BY posted_at");
    while ($post = $posts->fetch_assoc()) {
        echo "<div style='margin:10px;padding:8px;border:1px solid #ccc;'>
        <b>{$post['username']}:</b> {$post['body']}
        <small style='float:right'>{$post['posted_at']}</small></div>";
    }

    // Post reply
    ?>
    <form method="POST">
        <input type="hidden" name="content_id" value="<?= $content['content_id'] ?>">
        <textarea name="body" required></textarea>
        <button type="submit">Reply</button>
    </form>
    <?php
}

if ($_POST) {
    $stmt = $conn->prepare("INSERT INTO forum_posts (content_id, user_id, body) VALUES (?, ?, ?)");
    $stmt->bind_param("iis", $_POST['content_id'], $_SESSION['user_id'], $_POST['body']);
    $stmt->execute();
    echo "<script>location.reload();</script>";
}
?>