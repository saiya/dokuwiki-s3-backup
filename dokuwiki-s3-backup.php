<?php
define("HELP", <<<EOS
Backup dokuwiki directory to Amazon S3.

Usage:
  # Prepare .env file at first (see below)
  php dokuwiki-s3-backup.php /var/www/default/dokuwiki

.env file
  $ cp .env.example .env
  $ emacs .env

EOS
);

require_once(__DIR__ . '/vendor/autoload.php');  // composer

Dotenv::load(__DIR__);
Dotenv::required(array(
	'AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY',
	'S3_REGION', 'S3_BUCKET',
	'ZIP_PASSWORD',
	'TIMEZONE', 'S3_KEY',
));

date_default_timezone_set(getenv('TIMEZONE'));


function main($doku_dir){
	$zip_time = time();
	$zip_file = mkzip($doku_dir, getenv('ZIP_PASSWORD'));
	echo "Created temporary zip (" . filesize($zip_file) . ' bytes) on ' . $zip_file . "\n";
	
	$s3 = Aws\S3\S3Client::factory(array(
		'key'    => getenv('AWS_ACCESS_KEY_ID'),
		'secret' => getenv('AWS_SECRET_ACCESS_KEY'),
		'region' => getenv('S3_REGION'),
	));
	$s3_obj = $s3->putObject(array(
		'Bucket'        => getenv('S3_BUCKET'),
		'Key'           => format_key(getenv('S3_KEY'), $zip_time),
		'SourceFile'    => $zip_file,
		'ContentLength' => filesize($zip_file),
		'Content-MD5'   => base64_encode(md5_file($zip_file, true)),
		'ContentType'   => 'application/zip',
		'ServerSideEncryption' => 'AES256',
		'StorageClass'  => (getenv('S3_STORAGE_CLASS') ? getenv('S3_STORAGE_CLASS') : 'STANDARD'), 
		'Metadata'      => array(
			'ctime' => date('c', $zip_time),
			'sha-1' => strtolower(sha1_file($zip_file, false)),
		),
	));
	echo 'Successfully put to S3 (' . $s3_obj['ObjectURL'] . ')' . "\n";

	unlink($zip_file);
	echo "Deleted temporary zip" . "\n";
	return 0;
}
function mkzip($dir, $password){
	$compress_current = dirname($dir);
	$compress_src = basename($dir);
	$dest = sys_get_temp_dir() . '/' . uniqid('doku-s3-bak-' . getmypid() . '-') . '.zip';
	$command = 'zip -P ' . escapeshellarg($password) . ' ' . escapeshellarg($dest) . ' -r ' . escapeshellarg($compress_src);
	foreach(array('/data/locks/*.lock', '/data/deleted.files/*', '/data/cache-backup/*/*', '/data/cache/*/*') as $ignore){
		$command .= ' -x ' . escapeshellarg($compress_src . $ignore);
	}
	$command .= ' > /dev/null';

	echo "pushd $compress_current; $command\n";
	$cwd = getcwd();
	chdir($compress_current);
	system($command, $retval);
	chdir($cwd);

	if($retval != 0) throw new Exception("zip command returns non-zero: $command");
	if(! is_file($dest)) throw new Exception("zip command returns zero but no output file: $command");
	return $dest;
}
function format_key($format, $time){
	$result = $format;
	foreach(array(
		'%Y' => date('Y', $time),
		'%M' => date('m', $time),
		'%D' => date('d', $time),
		'%H' => date('H', $time),
		'%I' => date('i', $time),
		'%S' => date('s', $time),
	) as $k => $v){
		$result = str_replace($k, $v, $result);
	}
	return $result;
}


$dir = $argv[1];
if(is_dir($dir)){
	exit(main($dir));
}else{
	echo HELP;
}
