<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <title>Crash</title>
    <link rel="icon" href="/favicon.gif">
    <link rel="stylesheet" href="http://maxcdn.bootstrapcdn.com/bootstrap/3.3.6/css/bootstrap.min.css">
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/1.12.2/jquery.min.js"></script>
    <script src="http://maxcdn.bootstrapcdn.com/bootstrap/3.3.6/js/bootstrap.min.js"></script>
<style>
table {
    border-collapse: collapse;
    width: 100%;
}

th, td {
    vertical-align: top;
    text-align: left;
    padding: 8px;
}

tr:nth-child(even){background-color: #f2f2f2}

th {
    background-color: #4CAF50;
    color: white;
}

.monospace {
    font-family: monospace;
    font-size: 80%;
    white-space: pre-wrap;
}
</style>
  </head>
  <body>
<?php
function updateSymbols($dmpFile, $symbolsDir)
{
	$command = "bash get-microsoft-symbols.sh $dmpFile $symbolsDir";
	$text = shell_exec($command);
	return "<div class=\"monospace\">" . $text . "</div>";
}

function decodeDmpFile($exe, $dmpFile, $symbolsDir)
{
	$command = "$exe $dmpFile $symbolsDir";
	$text = shell_exec($command);

	$lines = explode(PHP_EOL,$text);
	$id = 0;
	$state = "";
	$output = "";
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
		else if($state != "" && preg_match("/^\s*\d+\s*.*$/", $line))
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

		$output .= $pre . htmlspecialchars($line) . $post . "\n";
		$pre = "";
	}

	return "<div class=\"monospace\">" . $output . "</div>";
}

try
{
	$settings = json_decode(file_get_contents("settings.json"), true);
	$fromEmail = $settings['fromEmail'];
	$reportEmail = $settings['reportEmail'];
	$baseUrl = $settings['baseUrl'];
	$minidumpExecutable = $settings['minidumpExecutable'];
	$symbolsDir = $settings['symbolsDir'];

	$db = new PDO('sqlite:reports.sqlite3');
	$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

	$db->exec("CREATE TABLE IF NOT EXISTS reports (
		id INTEGER PRIMARY KEY,
		email TEXT,
		text TEXT,
		product TEXT,
		version TEXT,
		os TEXT,
		gl TEXT,
		time INTEGER,
		ip TEXT,
		file TEXT,
		attachments TEXT)");

	if($_GET["id"] != NULL)
	{
		$id = $_GET["id"];

		$select = "SELECT email, text, product, version, os, gl, time, ip, file, attachments FROM reports WHERE id = :id";
		$statement = $db->prepare($select);
		$statement->bindParam(':id', $id);

		$statement->execute();
		$row = $statement->fetch(PDO::FETCH_ASSOC);

		echo "<table>\n";
		if($row != NULL)
		{
			$symbols = updateSymbols($row['file'], $symbolsDir);
			echo "<tr><td>Time</td><td>" . date('H:m:s d/m/Y', $row['time']) . "</td></tr>\n";
			echo "<tr><td>Address</td><td>" . gethostbyaddr($row['ip']) . " (" . $row['ip'] . ")</td></tr>\n";
			echo "<tr><td>Email</td><td><a href=\"mailto:" . $row['email'] . "\">" . $row['email'] . "</a></td></tr>\n";
			echo "<tr><td>dmp File</td><td><a href=\"" . $row['file'] . "\">" . $row['file'] . "</a></td></tr>\n";

			$attachments = unserialize($row['attachments']);

			if(!empty($attachments))
			{
				echo "<tr><td>Attachments</td><td>\n";
				foreach($attachments as $attachment)
				{
					if(exif_imagetype($attachment) !== false)
					{
						$thumbnail = "image.php?url=" . base64_encode($attachment) . "&height=200";
						echo "<a href=\"" . $attachment . "\"><img src=\"" . $thumbnail . "\"></a>\n";
					}
					else
						echo "<a href=\"" . $attachment . "\">" . basename($attachment) . "</a>\n";
				}
				echo "</td></tr>\n";
			}

			echo "<tr><td>Description</td><td>" . nl2br($row['text']) . "</td></tr>\n";
			echo "<tr><td>Product</td><td>" . $row['product'] . " " . $row['version'] . "</td></tr>\n";
			echo "<tr><td>OS</td><td>" . $row['os'] . "</td></tr>\n";
			echo "<tr><td>Stack</td><td>" . decodeDmpFile($minidumpExecutable, $row['file'], $symbolsDir) . "</td></tr>\n";
			echo "<tr><td>OpenGL</td><td><div class=\"monospace\">" . $row['gl'] . "</div></td></tr>\n";
			echo "<tr><td>Symbols</td><td>" . $symbols . "</td></tr>\n";
		}
		echo "</table>\n";
	}
	else
	{
		if(!empty($_SERVER['HTTP_CLIENT_IP']))
		{
		  $ip = $_SERVER['HTTP_CLIENT_IP'];
		}
		elseif(!empty($_SERVER['HTTP_X_FORWARDED_FOR']))
		{
		  $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
		}
		else
		{
		  $ip = $_SERVER['REMOTE_ADDR'];
		}

		$time = time();

		foreach($_POST as $key => $value)
		{
			switch($key)
			{
				case "email":		$email = $value; break;
				case "text":		$text = $value; break;
				case "product":		$product = $value; break;
				case "version":		$version = $value; break;
				case "os":		$os = $value; break;
				case "gl":		$gl = $value; break;
				case "checksum":	$checksum = $value; break;
			}
		}

		$localChecksum = md5($email . $text . $product . $version . $os . $gl);
		if($checksum != $localChecksum)
			throw new Exception('bad checksum');

		$dir = 'dmps';
		if(!file_exists($dir) && !mkdir($dir))
			throw new Exception('mkdir failed');

		$file = $dir . '/' . basename($_FILES['dmp']['name']);

		if(!move_uploaded_file($_FILES['dmp']['tmp_name'], $file))
			throw new Exception('move_uploaded_file failed');

		$attachmentsDir = $dir . '/' . basename($file, ".dmp");
		$attachments = [];

		foreach($_FILES as $fieldName => $keys)
		{
			if(preg_match('#^attachment#', $fieldName) === 1)
			{
				if(!file_exists($attachmentsDir) && !mkdir($attachmentsDir))
					throw new Exception('mkdir failed');

				$attachment = $attachmentsDir . '/' . basename($_FILES[$fieldName]['name']);

				if(!move_uploaded_file($_FILES[$fieldName]['tmp_name'], $attachment))
					throw new Exception('move_uploaded_file failed');

				$attachments[] = $attachment;
			}
		}

		$insert = "INSERT INTO reports (email, text, product, version, os, gl, time, ip, file, attachments)
			VALUES(:email, :text, :product, :version, :os, :gl, :time, :ip, :file, :attachments)";
		$statement = $db->prepare($insert);
		$statement->bindParam(':email', $email);
		$statement->bindParam(':text', $text);
		$statement->bindParam(':product', $product);
		$statement->bindParam(':version', $version);
		$statement->bindParam(':os', $os);
		$statement->bindParam(':gl', $gl);
		$statement->bindParam(':time', $time);
		$statement->bindParam(':ip', $ip);
		$statement->bindParam(':file', $file);
		$statement->bindParam(':attachments', serialize($attachments));

		$statement->execute();

		$id = $db->lastInsertId();

		$message = "<!DOCTYPE html><html lang=\"en\"><head><meta charset=\"utf-8\"></head><body>\n";
		if(strlen($email) > 0)
			$message .= "$email says:<br>";
		if(strlen($text) > 0)
			$message .= "$text<br><br>";
		$message .= "<a href=\"$baseUrl?id=$id\">Crash Report</a><br>";
		$message .= "</body></html>\n";

		$headers = 'From:' . $fromEmail . "\r\n" .
			'X-Mailer: PHP/' . phpversion() . "\r\n" .
			'MIME-Version: 1.0' . "\r\n" .
			'Content-type: text/html; charset=iso-8859-1' . "\r\n";

		mail($reportEmail, "$product $version Crash", $message, $headers);
	}
}
catch(Exception $e)
{
	echo $e->getMessage();
}
?>
  </body>
</html>
