<?php
if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}

$password = $argv[1] ?? '';
if ($password === '') {
	fwrite(STDERR, "Usage: php hash.php <password>\n");
	exit(1);
}

echo password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]), PHP_EOL;
?>