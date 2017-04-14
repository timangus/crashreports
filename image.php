<?php

$imgUrl = base64_decode($_GET["url"]);
$c = file_get_contents($imgUrl);
$arr = getimagesizefromstring($c);

if(!is_array($arr))
	return;

header("Content-Type: image/jpeg");

$img = imagecreatefromstring($c);

List($width, $height) = getimagesize($imgUrl);

if(isset($_GET["width"]))
{
	$w = $_GET["width"];
	$h = $height * ($w / $width);
}
else if(isset($_GET["height"]))
{
	$h = $_GET["height"];
	$w = $width * ($h / $height);
}
else
	return;

$newImageBase = imagecreatetruecolor($w, $h);

imagecopyresampled($newImageBase, $img, 0, 0, 0, 0, $w, $h, $width, $height);
$img = $newImageBase;

imagejpeg($img);
?>
