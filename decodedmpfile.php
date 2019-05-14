<?php
header("Content-Type: text/plain");

if(array_key_exists("dmpFile", $_GET) && array_key_exists("symbolsDir", $_GET))
{
  $dmpFile = $_GET["dmpFile"];
  $symbolsDir = $_GET["symbolsDir"];

  $settings = json_decode(file_get_contents("settings.json"), true);
  $exe = $settings['minidumpExecutable'];

	$command = "$exe $dmpFile $symbolsDir";
	$text = shell_exec($command);

	$lines = explode(PHP_EOL,$text);
	$id = 0;
	$state = "";
	$output = "";
	$nextpre = "";
	foreach($lines as $line)
	{
		$pre = $nextpre;
		$nextpre = "";
		$post = "";

		if(trim($line) == "")
		{
			if($state != "")
				$pre = "</div>";

			$state = "";
		}
		else if($state == "" && preg_match("/^Thread \d+.*$/", $line))
			$state = "thread";
		else if($state != "")
		{
			if(preg_match("/^\s*\d+\s*.*$/", $line))
			{
				$stackFrameId = "stackframe" . $id++;
				if($state == "thread")
					$state = "stackframes";
				else
					$pre = "</div>";

				$pre .= "<a data-toggle=\"collapse\" href=\"#$stackFrameId\">";
				$post = "</a>";
				$nextpre = "<div id=\"$stackFrameId\" class=\"collapse\">";
			}
			else if($state == "thread")
				$state = "";
		}

		$output .= $pre . htmlspecialchars($line) . $post . "\n";
		$pre = "";
	}

	echo "<div class=\"monospace\">" . $output . "</div>";
}
else
{
  echo "Error";
}
?>
