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
		else if($state == "" && preg_match("/^Thread \d+ \(crashed\)$/", $line))
			$state = "threadcrashed";
		else if($state == "" && preg_match("/^Thread \d+.*$/", $line))
			$state = "thread";
		else if($state != "")
		{
			if(preg_match("/^\s*\d+\s*.*$/", $line))
			{
				$stackFrameId = "stackframe" . $id++;
				if(strpos($state, "thread") === 0)
				{
					if($state === "threadcrashed")
					{
						preg_match("/^\s*\d+\s*([^ \(]*).*$/", $line, $matches);
						$crashLocation = $matches[1];
					}

					$state = "stackframes";
				}
				else
					$pre = "</div>";

				$pre .= "<a data-toggle=\"collapse\" href=\"#$stackFrameId\">";
				$post = "</a>";
				$nextpre = "<div id=\"$stackFrameId\" class=\"collapse\">";
			}
			else if(strpos($state, "thread") === 0)
				$state = "";
		}

		$output .= $pre . htmlspecialchars($line) . $post . "\n";
		$pre = "";
	}

	echo "<div class=\"monospace\">" . $output . "</div>";

	if(strlen($crashLocation) > 0 && array_key_exists("id", $_GET))
	{
		$id = $_GET['id'];

		$db = new PDO('sqlite:reports.sqlite3');
		$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

		// Set the crashLocation field so that we can hint in the overview where the crash occurred
		$update = "UPDATE reports SET crashLocation = :crashLocation WHERE id = :id";
		$statement = $db->prepare($update);
		$statement->bindParam(':crashLocation', $crashLocation);
		$statement->bindParam(':id', $id);

		$statement->execute();
	}
}
else
{
	echo "Error";
}
?>
