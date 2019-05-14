<?php
header("Content-Type: text/plain");

if(array_key_exists("dmpFile", $_GET) && array_key_exists("symbolsDir", $_GET))
{
  $dmpFile = $_GET["dmpFile"];
  $symbolsDir = $_GET["symbolsDir"];
	$command = "bash get-microsoft-symbols.sh $dmpFile $symbolsDir";
	$text = shell_exec($command);
	echo $text;
}
else
{
  echo "Error";
}
?>
