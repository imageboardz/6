<?php
// ------------------ Configuration and Setup ------------------ //
error_reporting(E_ALL);
ini_set('display_errors', '0');    // In production, keep off. For development, set '1'.
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/error.log');
session_start();

// Configuration
define('ADELIA_BOARD_DESC', 'Adelia Imageboard');
define('ADELIA_THREADS_PER_PAGE', 10);
define('ADELIA_REPLIES_PREVIEW', 3);
define('ADELIA_MAX_LINES', 15);
define('ADELIA_TIMEZONE', 'UTC');
define('ADELIA_MAX_FILE_SIZE', 2048 * 1024); // 2 MB
define('ADELIA_UPLOAD_DIR', __DIR__ . '/uploads/');
define('ADELIA_THUMB_DIR', __DIR__ . '/thumbs/');
define('ADELIA_ALLOWED_MIME', ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif']);
define('ADELIA_THUMB_WIDTH', 250);
define('ADELIA_THUMB_HEIGHT', 250);

// Ensure directories exist
foreach ([ADELIA_UPLOAD_DIR, ADELIA_THUMB_DIR] as $dir) {
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0755, true)) {
            error_log("Failed to create directory: $dir");
            die("An unexpected error occurred.");
        }
    }
}

// Set timezone
date_default_timezone_set(ADELIA_TIMEZONE);

// ------------------ Database Initialization ------------------ //
try {
    $db = new SQLite3(__DIR__ . '/adelia.db', SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
} catch (Exception $e) {
    error_log('DB Connection Failed: ' . $e->getMessage());
    die('An unexpected error occurred.');
}

// Strengthen DB integrity
$db->exec("PRAGMA foreign_keys = ON;");
$db->exec("CREATE TABLE IF NOT EXISTS posts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    parent INTEGER NOT NULL,
    timestamp INTEGER NOT NULL,
    bumped INTEGER NOT NULL,
    ip TEXT NOT NULL,
    title TEXT,
    message TEXT NOT NULL,
    file TEXT,
    thumb TEXT
)");
$db->exec("CREATE INDEX IF NOT EXISTS parent_index ON posts(parent);");
$db->exec("CREATE INDEX IF NOT EXISTS bumped_index ON posts(bumped);");

// ------------------ Utility Functions ------------------ //
function sanitize(string $input, int $maxLength = 2000): string {
    $input = trim($input);
    if (strlen($input) > $maxLength) {
        $input = substr($input, 0, $maxLength);
    }
    return htmlspecialchars($input, ENT_QUOTES, 'UTF-8');
}

function generateCsrfToken() {
    $token = bin2hex(random_bytes(32));
    $_SESSION['csrf_token'] = $token;
    return $token;
}

function verifyCsrfToken($token) {
    return (isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token));
}

function generateUniqueFilename(string $extension): string {
    return bin2hex(random_bytes(16)) . '.' . $extension;
}

function createThumbnail(string $sourcePath, string $thumbPath): bool {
    $imageInfo = @getimagesize($sourcePath);
    if (!$imageInfo) {
        error_log('Invalid image file: ' . $sourcePath);
        return false;
    }
    list($width, $height, $type) = $imageInfo;

    switch ($type) {
        case IMAGETYPE_JPEG:
            $srcImg = imagecreatefromjpeg($sourcePath);
            break;
        case IMAGETYPE_PNG:
            $srcImg = imagecreatefrompng($sourcePath);
            break;
        case IMAGETYPE_GIF:
            $srcImg = imagecreatefromgif($sourcePath);
            break;
        default:
            return false;
    }

    if (!$srcImg) {
        error_log('Failed to create image from source: ' . $sourcePath);
        return false;
    }

    $ratio = min(ADELIA_THUMB_WIDTH / $width, ADELIA_THUMB_HEIGHT / $height);
    $thumbWidth = (int)($width * $ratio);
    $thumbHeight = (int)($height * $ratio);

    $thumbImg = imagecreatetruecolor($thumbWidth, $thumbHeight);

    // Preserve transparency
    if ($type == IMAGETYPE_PNG || $type == IMAGETYPE_GIF) {
        imagecolortransparent($thumbImg, imagecolorallocatealpha($thumbImg, 0, 0, 0, 127));
        imagealphablending($thumbImg, false);
        imagesavealpha($thumbImg, true);
    }

    if (!imagecopyresampled($thumbImg, $srcImg, 0, 0, 0, 0, $thumbWidth, $thumbHeight, $width, $height)) {
        error_log('Failed to resample image.');
        imagedestroy($srcImg);
        imagedestroy($thumbImg);
        return false;
    }

    $saved = false;
    switch ($type) {
        case IMAGETYPE_JPEG:
            $saved = imagejpeg($thumbImg, $thumbPath, 85);
            break;
        case IMAGETYPE_PNG:
            $saved = imagepng($thumbImg, $thumbPath);
            break;
        case IMAGETYPE_GIF:
            $saved = imagegif($thumbImg, $thumbPath);
            break;
    }

    imagedestroy($srcImg);
    imagedestroy($thumbImg);

    if (!$saved) {
        error_log('Failed to save thumbnail: ' . $thumbPath);
    }
    return $saved;
}

function getThreads(int $page, int $perPage, SQLite3 $db): array {
    $offset = ($page - 1) * $perPage;
    $stmt = $db->prepare("SELECT * FROM posts WHERE parent = 0 ORDER BY bumped DESC LIMIT :limit OFFSET :offset");
    $stmt->bindValue(':limit', $perPage, SQLITE3_INTEGER);
    $stmt->bindValue(':offset', $offset, SQLITE3_INTEGER);
    $result = $stmt->execute();

    $threads = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $row['reply_count'] = countReplies($row['id'], $db);
        $threads[] = $row;
    }
    return $threads;
}

function getTotalThreads(SQLite3 $db): int {
    $result = $db->querySingle("SELECT COUNT(*) as count FROM posts WHERE parent = 0", true);
    return (int)$result['count'];
}

function getReplies(int $threadId, SQLite3 $db): array {
    $stmt = $db->prepare("SELECT * FROM posts WHERE parent = :parent ORDER BY timestamp ASC");
    $stmt->bindValue(':parent', $threadId, SQLITE3_INTEGER);
    $result = $stmt->execute();

    $replies = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $replies[] = $row;
    }
    return $replies;
}

function insertPost(SQLite3 $db, array $data): int {
    $stmt = $db->prepare("INSERT INTO posts (parent, timestamp, bumped, ip, title, message, file, thumb) VALUES (:parent, :timestamp, :bumped, :ip, :title, :message, :file, :thumb)");
    $stmt->bindValue(':parent', $data['parent'], SQLITE3_INTEGER);
    $stmt->bindValue(':timestamp', time(), SQLITE3_INTEGER);
    $stmt->bindValue(':bumped', time(), SQLITE3_INTEGER);
    $stmt->bindValue(':ip', $_SERVER['REMOTE_ADDR'], SQLITE3_TEXT);
    $stmt->bindValue(':title', $data['title'], SQLITE3_TEXT);
    $stmt->bindValue(':message', $data['message'], SQLITE3_TEXT);
    $stmt->bindValue(':file', $data['file'], SQLITE3_TEXT);
    $stmt->bindValue(':thumb', $data['thumb'], SQLITE3_TEXT);
    $stmt->execute();

    return (int)$db->lastInsertRowID();
}

function countReplies(int $threadId, SQLite3 $db): int {
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM posts WHERE parent = :parent");
    $stmt->bindValue(':parent', $threadId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);
    return (int)$row['count'];
}

function escapeOutput(string $text): string {
    return nl2br($text);
}

function displayPagination(int $currentPage, int $threadsPerPage, SQLite3 $db): string {
    $totalPages = (int)ceil(getTotalThreads($db) / $threadsPerPage);
    if ($totalPages <= 1) {
        return '';
    }

    $pagination = '<div class="pagination">';
    if ($currentPage > 1) {
        $pagination .= '<a href="?page=' . ($currentPage - 1) . '">&laquo; Previous</a>';
    } else {
        $pagination .= '<span class="disabled">&laquo; Previous</span>';
    }

    for ($i = 1; $i <= $totalPages; $i++) {
        if ($i === $currentPage) {
            $pagination .= '<span class="current">' . $i . '</span>';
        } else {
            $pagination .= '<a href="?page=' . $i . '">' . $i . '</a>';
        }
    }

    if ($currentPage < $totalPages) {
        $pagination .= '<a href="?page=' . ($currentPage + 1) . '">Next &raquo;</a>';
    } else {
        $pagination .= '<span class="disabled">Next &raquo;</span>';
    }

    $pagination .= '</div>';
    return $pagination;
}

// Basic Anti-Spam: Check if the user posted too recently
function canUserPost($db, $ip) {
    $stmt = $db->prepare("SELECT timestamp FROM posts WHERE ip = :ip ORDER BY timestamp DESC LIMIT 1");
    $stmt->bindValue(':ip', $ip, SQLITE3_TEXT);
    $result = $stmt->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);
    if (!$row) return true; // no posts yet

    $lastPostTime = (int)$row['timestamp'];
    // 15-second delay between posts as a simple measure
    return (time() - $lastPostTime) > 15;
}

// ------------------ Handle POST Requests ------------------ //
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $parent = isset($_POST['parent']) ? (int)$_POST['parent'] : 0;
    $title = ($parent === 0 && isset($_POST['name'])) ? sanitize($_POST['name'], 75) : '';
    $message = isset($_POST['message']) ? sanitize($_POST['message'], 8000) : '';
    $csrfToken = $_POST['csrf_token'] ?? '';

    // CSRF Check
    if (!verifyCsrfToken($csrfToken)) {
        die('Invalid CSRF token.');
    }

    // Basic Validation
    if (($parent === 0 && empty($title)) || empty($message)) {
        die('Title and message cannot be empty.');
    }

    // Spam Check
    if (!canUserPost($db, $_SERVER['REMOTE_ADDR'])) {
        die('You are posting too quickly. Please wait before posting again.');
    }

    // File Upload Handling
    $file = null;
    $thumb = null;
    if (isset($_FILES['file']) && $_FILES['file']['error'] !== UPLOAD_ERR_NO_FILE) {
        if ($_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            die('File upload error.');
        }

        if ($_FILES['file']['size'] > ADELIA_MAX_FILE_SIZE) {
            die('File exceeds maximum allowed size of 2 MB.');
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $_FILES['file']['tmp_name']);
        finfo_close($finfo);

        if (!array_key_exists($mime, ADELIA_ALLOWED_MIME)) {
            die('Unsupported file type.');
        }

        $extension = ADELIA_ALLOWED_MIME[$mime];
        $uniqueFilename = generateUniqueFilename($extension);
        $destination = ADELIA_UPLOAD_DIR . $uniqueFilename;

        if (!move_uploaded_file($_FILES['file']['tmp_name'], $destination)) {
            die('Failed to move uploaded file.');
        }

        // Create Thumbnail
        $thumbFilename = 'thumb_' . $uniqueFilename;
        $thumbPath = ADELIA_THUMB_DIR . $thumbFilename;
        if (!createThumbnail($destination, $thumbPath)) {
            unlink($destination);
            die('Failed to create thumbnail.');
        }

        $file = $uniqueFilename;
        $thumb = $thumbFilename;
    }

    // Insert post
    $postData = [
        'parent' => $parent,
        'title' => $title,
        'message' => $message,
        'file' => $file,
        'thumb' => $thumb
    ];

    try {
        $postId = insertPost($db, $postData);

        if ($parent !== 0) {
            $stmt = $db->prepare("UPDATE posts SET bumped = :bumped WHERE id = :id");
            $stmt->bindValue(':bumped', time(), SQLITE3_INTEGER);
            $stmt->bindValue(':id', $parent, SQLITE3_INTEGER);
            $stmt->execute();
        }

        // Redirect to the appropriate page
        if ($parent === 0) {
            header('Location: ?');
        } else {
            header('Location: ?thread=' . $parent . '#post' . $postId);
        }
        exit;
    } catch (Exception $e) {
        error_log('Insert post failed: ' . $e->getMessage());
        die('An unexpected error occurred.');
    }
}

// Determine current page
$currentPage = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;

// Generate CSRF Token for forms
$csrfToken = generateCsrfToken();

// Fetch threads for current page if not in a thread view
$threads = [];
if (!isset($_GET['thread'])) {
    $threads = getThreads($currentPage, ADELIA_THREADS_PER_PAGE, $db);
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars(ADELIA_BOARD_DESC) ?></title>
    <link rel="stylesheet" href="adelia.css">
    <script>
        function togglePostForm() {
            const formContainer = document.getElementById('post-form-container');
            const createButton = document.getElementById('create-thread-button');
            if (formContainer.style.display === 'none') {
                formContainer.style.display = 'block';
                createButton.style.display = 'none';
            } else {
                formContainer.style.display = 'none';
                createButton.style.display = 'block';
            }
        }
    </script>
</head>
<body>
<?php
// Thread view
if (isset($_GET['thread'])) {
    $threadId = (int)$_GET['thread'];

    $stmt = $db->prepare("SELECT * FROM posts WHERE id = :id AND parent = 0 LIMIT 1");
    $stmt->bindValue(':id', $threadId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $mainThread = $result->fetchArray(SQLITE3_ASSOC);

    if ($mainThread) {
        // Reply Mode
        echo '<div class="replymode">';
        echo '<strong>Reply Mode</strong> | <a href="' . htmlspecialchars($_SERVER['PHP_SELF']) . '">Back to Main Board</a>';
        echo '</div>';

        // Reply Form
        echo '<div class="postarea-container">';
        echo '<form class="postform" action="" method="post" enctype="multipart/form-data">';
        echo '<input type="hidden" name="parent" value="' . $threadId . '">';
        echo '<input type="hidden" name="csrf_token" value="' . $csrfToken . '">';
        echo '<textarea id="message" name="message" rows="4" maxlength="8000" placeholder="Reply" required></textarea>';

        echo '<label for="file">Image (optional)</label>';
        echo '<input type="file" id="file" name="file" accept="image/jpeg,image/png,image/gif">';

        echo '<input type="submit" value="Reply">';
        echo '</form>';
        echo '</div>';

        echo '<hr>';

        // Main Thread Post
        echo '<div class="thread">';
        echo '<div class="row1">';
        if (!empty($mainThread['file'])) {
            echo '<img class="post-image" src="thumbs/' . htmlspecialchars($mainThread['thumb']) . '" alt="Image">';
        }
        echo '<span class="title">' . (!empty($mainThread['title']) ? htmlspecialchars($mainThread['title']) : '') . '</span>';
        echo '<a href="#" class="reply-link" style="float: right;">Reply (' . countReplies($mainThread['id'], $db) . ')</a><br>';
        echo '<span class="message">' . escapeOutput($mainThread['message']) . '</span><br>';
        echo '</div></div><hr>';

        // Replies
        $replies = getReplies($threadId, $db);
        foreach ($replies as $reply) {
            echo '<div class="reply" id="post' . $reply['id'] . '">';
            if (!empty($reply['file'])) {
                echo '<img class="post-image" src="thumbs/' . htmlspecialchars($reply['thumb']) . '" alt="Image">';
            }
            echo '<span class="message">' . escapeOutput($reply['message']) . '</span><br>';
            echo '</div><hr>';
        }
    } else {
        echo '<p>Thread not found.</p>';
    }
} else {
    // Main Board View
    echo '<div class="adminbar">';
    echo '<!-- Future admin links can go here -->';
    echo '</div>';
    echo '<div class="logo">';
    echo htmlspecialchars(ADELIA_BOARD_DESC);
    echo '</div>';
    echo '<hr>';

    echo '<button id="create-thread-button" onclick="togglePostForm()" style="display: block;">Create New Thread</button>';

    echo '<div id="post-form-container" style="display: none;">';
    echo '<div class="postarea-container">';
    echo '<form class="postform" action="" method="post" enctype="multipart/form-data">';
    echo '<input type="hidden" name="parent" value="0">';
    echo '<input type="hidden" name="csrf_token" value="' . $csrfToken . '">';
    echo '<input type="text" id="name" name="name" maxlength="75" placeholder="Title" required>';

    echo '<textarea id="message" name="message" rows="4" maxlength="8000" placeholder="Message" required></textarea>';

    echo '<label for="file">Image (optional)</label>';
    echo '<input type="file" id="file" name="file" accept="image/jpeg,image/png,image/gif">';

    echo '<input type="submit" value="Post">';
    echo '</form>';
    echo '<button onclick="togglePostForm()">[X] Close</button>';
    echo '</div>';
    echo '</div>';

    echo '<hr>';

    // Display Threads
    foreach ($threads as $thread) {
        echo '<div class="thread">';
        echo '<div class="row1">';

        if (!empty($thread['file'])) {
            echo '<img class="post-image" src="thumbs/' . htmlspecialchars($thread['thumb']) . '" alt="Image">';
        }

        echo '<span class="title">' . (!empty($thread['title']) ? htmlspecialchars($thread['title']) : '') . '</span>';
        echo '<a href="?thread=' . $thread['id'] . '" class="reply-link" style="float: right;">Reply (' . $thread['reply_count'] . ')</a><br>';

        // Truncate message to a reasonable length
        $message = $thread['message'];
        $maxLines = ADELIA_MAX_LINES;
        $lines = explode("\n", $message);
        if (count($lines) > $maxLines) {
            $lines = array_slice($lines, 0, $maxLines);
            $displayMessage = implode("\n", $lines) . "\n[...]\n";
        } else {
            $displayMessage = $message;
        }

        echo '<span class="message">' . escapeOutput($displayMessage) . '</span><br>';

        echo '</div><hr>';
    }

    // Pagination
    echo displayPagination($currentPage, ADELIA_THREADS_PER_PAGE, $db);
}

?>

<hr>
<div class="footer">
    - <a href="https://example.com" target="_blank">Adelia</a> -
</div>
</body>
</html>
