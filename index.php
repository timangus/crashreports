<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <title>Crash</title>
    <link rel="icon" href="/favicon.gif">
    <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.6/css/bootstrap.min.css">
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/1.12.2/jquery.min.js"></script>
    <script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.6/js/bootstrap.min.js"></script>

<script>
function updateSymbols(id, dmpFile, symbolsDir)
{
  var req = new XMLHttpRequest();
  req.addEventListener("load", function()
  {
    var output = this.responseText;

    var element = document.getElementById("symbols");
    element.innerHTML = "<div class=\"monospace\">" + output + "</div>";

    // This may have completed already, but do it again
    // since we may have new symbols
    decodeDmpFile(id, dmpFile, symbolsDir);
  });

  req.open("GET", "updatesymbols.php?dmpFile=" +
    dmpFile + "&symbolsDir=" + symbolsDir);
  req.send();
}

function decodeDmpFile(id, dmpFile, symbolsDir)
{
  var req = new XMLHttpRequest();
  req.addEventListener("load", function()
  {
    var output = this.responseText;

    var element = document.getElementById("stack");
    element.innerHTML = output;
  });

  req.open("GET", "decodedmpfile.php?id=" + id + "&dmpFile=" +
    dmpFile + "&symbolsDir=" + symbolsDir);
  req.send();
}
</script>

<style>
table {
    border-collapse: collapse;
    width: 100%;
}

th, td {
    vertical-align: top;
    text-align: left;
    padding: 8px;
    white-space: nowrap;
}

.fillWidth {
    white-space: normal;
}

tr:nth-child(even){background-color: #f2f2f2}

.hoverTable tr:hover {
    background-color: #ACEFA0;
}

th {
    background-color: #4CAF50;
    color: white;
}

.monospace {
    font-family: monospace;
    font-size: 80%;
    white-space: pre-wrap;
}

.loader {
    border: 8px solid #cccccc; /* Light grey */
    border-top: 8px solid #3498db; /* Blue */
    border-radius: 50%;
    width: 48px;
    height: 48px;
    animation: spin 2s linear infinite;
}

@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}
</style>
  </head>
<?php
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
		attachments TEXT,
		crashLocation TEXT)");

	if(array_key_exists("id", $_GET))
	{
		$id = $_GET["id"];

		$select = "SELECT email, text, product, version, os, gl, time, ip, file, attachments FROM reports WHERE id = :id";
		$statement = $db->prepare($select);
		$statement->bindParam(':id', $id);

		$statement->execute();
		$row = $statement->fetch(PDO::FETCH_ASSOC);

		if($row != NULL)
		{
			$dmpFile = $row['file'];
			$os = $row['os'];

			$onLoad = "decodeDmpFile($id, '$dmpFile', '$symbolsDir');";

			# If it's a Windows crash report, try and download MS symbols
			if(strpos($os, "windows") !== false)
				$onLoad = "updateSymbols($id, '$dmpFile', '$symbolsDir'); " . $onLoad;

			echo "<body onLoad=\"$onLoad\">";
			echo "<table>\n";
			echo "<tr><td>Time</td><td>" . date('H:m:s d/m/Y', $row['time']) . "</td></tr>\n";
			echo "<tr><td>Address</td><td>" . gethostbyaddr($row['ip']) . " (" . $row['ip'] . ")</td></tr>\n";
			echo "<tr><td>Email</td><td><a href=\"mailto:" . $row['email'] . "\">" . $row['email'] . "</a></td></tr>\n";
			echo "<tr><td>dmp File</td><td><a href=\"$dmpFile\">$dmpFile</a></td></tr>\n";

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
			echo "<tr><td>OS</td><td>" . $os . "</td></tr>\n";

			if(strpos($os, "windows") !== false)
			{
				echo "<tr><td>Symbols</td>";
				echo "<td id=\"symbols\"><div class=\"loader\"></div></td></tr>\n";
			}

			echo "<tr><td>Stack</td>";
			echo "<td id=\"stack\"><div class=\"loader\"></div></td></tr>\n";
			echo "<tr><td>OpenGL</td><td><div class=\"monospace\">" . $row['gl'] . "</div></td></tr>\n";

			echo "</table>\n";
			echo "</body>\n";
		}
	}
	else if(array_key_exists("checksum", $_POST))
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
				case "email":     $email = $value; break;
				case "text":      $text = $value; break;
				case "product":   $product = $value; break;
				case "version":   $version = $value; break;
				case "os":        $os = $value; break;
				case "gl":        $gl = $value; break;
				case "checksum":  $checksum = $value; break;
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

		$headers = 'From: ' . $fromEmail . "\r\n" .
			'Reply-To: ' . $email . "\r\n" .
			'X-Mailer: PHP/' . phpversion() . "\r\n" .
			'MIME-Version: 1.0' . "\r\n" .
			'Content-type: text/html; charset=iso-8859-1' . "\r\n";

		mail($reportEmail, "$product $version Crash", $message, $headers);

		clearOldSymbols($symbolsDir);
	}
	else
	{
		echo "<body>";
		echo "<table class=\"hoverTable\">";
		echo "<tr>";

		echo "<th>Email</th>";
		echo "<th>Product</th>";
		echo "<th>Description</th>";
		echo "<th>Location</th>";
		echo "<th>Time</th>";

		echo "</tr>";

		$select = "SELECT id, email, text, product, version, crashLocation, time FROM reports " .
			"ORDER BY time DESC";
		$statement = $db->prepare($select);
		$statement->execute();

		while($row = $statement->fetch(PDO::FETCH_ASSOC))
		{
			$id = $row['id'];
			$email = $row['email'];
			$product = $row['product'] . " " . $row['version'];
			$htmlProduct = rawurlencode($product);
			$text = $row['text'];
			$time = $row['time'];
			$crashLocation = $row['crashLocation'];

			echo "<tr onclick=\"window.location='?id=$id';\">";

			echo "<td>";
			echo "<a href=\"mailto:$email?subject=$htmlProduct\">$email</a>";
			echo "</td>";
			echo "<td>$product</td>";
			echo "<td class=\"fillWidth\">$text</td>";
			echo "<td>$crashLocation</td>";
			echo "<td>" . date("H:i d-m-Y", $time) . "</td>";

			echo "</tr>";
		}

		echo "</table>";
		echo "</body>";
	}
}
catch(Exception $e)
{
	echo $e->getMessage();
}

function clearOldSymbols($symbolsDir)
{
	// Removes symbol files that are older than X months
	$MAX_AGE_MONTH = 3;
	$oldestTime = mktime(0, 0, 0, date("m")-$MAX_AGE_MONTH, date("d"), date("Y"));
	$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($symbolsDir));
	foreach($files as $file)
	{
		if(is_file($file->getRealPath()) && (filemtime($file) < $oldestTime))
		{
			error_log("Removing old symbol file: " . $file->getRealPath());

			$parentDirectory = realpath($file->getPath());
			unlink($file->getRealPath());
			if(is_dir_empty($parentDirectory))
				rmdir($parentDirectory);
		}
	}
}

function is_dir_empty($dir)
{
	if(!is_readable($dir))
		return NULL;

	$handle = opendir($dir);
	while(false !== ($entry = readdir($handle)))
	{
		if($entry != "." && $entry != "..")
			return FALSE;
	}
	return TRUE;
}
?>
</html>
