<?php
// Commentplugin
class YellowComment
{
	var $metaData;
	var $comment;
	
	function __construct()
	{
		$this->metaData = array();
	}

	// Set comment meta data
	function set($key, $value)
	{
		$this->metaData[$key] = $value;
	}
	
	// Return comment meta data
	function get($key)
	{
		return $this->isExisting($key) ? $this->metaData[$key] : "";
	}

	// Return comment meta data, HTML encoded
	function getHtml($key)
	{
		return htmlspecialchars($this->get($key));
	}
	
	// Check if comment meta data exists
	function isExisting($key)
	{
		return !is_null($this->metaData[$key]);
	}
	
	// Check if comment was published
	function isPublished()
	{
		return !$this->isExisting("published") || $this->get("published")=="yes";
	}
}

class YellowComments
{
	const Version = "0.1";
	var $yellow;			//access to API
	var $requiredField;
	var $comments;
	var $pageText;
	
	// Handle initialisation
	function onLoad($yellow)
	{
		$this->yellow = $yellow;
		$this->yellow->config->setDefault("commentsDir", "");
		$this->yellow->config->setDefault("commentsExtension", "-comments");
		$this->yellow->config->setDefault("commentsTemplate", "system/config/comment-template.txt");
		$this->yellow->config->setDefault("commentsSeparator", "----");
		$this->yellow->config->setDefault("commentsAutoAppend", "0");
		$this->yellow->config->setDefault("commentsAutoPublish", "0");
		$this->yellow->config->setDefault("commentsMaxSize", "10000");
		$this->yellow->config->setDefault("commentSpamFilter", "href=|url=");
		$this->requiredField = "";
		$this->cleanup();
	}

	// Cleanup datastructures
	function onParseContentRaw($page, $text)
	{
		return ($page->get("parser")=="Comments")?$this->yellow->text->get("commentsWebinterfaceModify"):$text;
	}

	// Handle page meta data parsing
	function onParseMeta($page)
	{
		if($page->get("parser")=="Comments") $page->visible = false;
	}

	// Cleanup datastructures
	function cleanup()
	{
		$this->comments = array();
		$this->pageText = "";
	}

	// Return file name from page object (depending on settings)
	function getCommentFileName($page)
	{
		if($this->yellow->config->get("commentsDir")=="")
		{
			$file = $page->fileName;
			$extension = $this->yellow->config->get("contentExtension");
			if(substru($file, strlenu($file)-strlenu($extension))==$extension)
				$file = substru($file, 0, strlenu($file)-strlenu($extension));
			$file .= $this->yellow->config->get("commentsExtension").$extension;
			return $file;
		} else {
			return $this->yellow->config->get("commentsDir").$page->get("pageFile");
		}
	}
	
	// Load comments from given file name
	function loadComments($page)
	{
		$file = $this->getCommentFileName($page);
		$this->cleanup();
		if(file_exists($file))
		{
			$contents = explode($this->yellow->config->get("commentsSeparator"), file_get_contents($file));
			if(count($contents>0))
			{
				$pageText = $contents[0];
				unset($contents[0]);
				foreach($contents as $content)
				{
					if(preg_match("/^(\xEF\xBB\xBF)?[\r\n]*\-\-\-[\r\n]+(.+?)[\r\n]+\-\-\-[\r\n]+(.*)/s", $content, $parts))
					{
						$comment = new YellowComment;
						foreach(preg_split("/[\r\n]+/", $parts[2]) as $line)
						{
							preg_match("/^\s*(.*?)\s*:\s*(.*?)\s*$/", $line, $matches);
							if(!empty($matches[1]) && !strempty($matches[2])) $comment->set(lcfirst($matches[1]), $matches[2]);
						}
						$comment->comment = trim($parts[3]);
						array_push($this->comments, $comment);
					}
				}
			}
		}
	}
	
	// Append comment
	function appendComment($page, $comment)
	{
		// TODO: create directory
		$file = $this->getCommentFileName($page);
		$status = "send";
		$content = "---\n";
		$content.= "Uid: ".$comment->get("uid")."\n";
		if($this->yellow->config->get("commentsAutoPublish")!="1") $content.= "Published: No\n";
		$content.= "Name: ".$comment->get("name")."\n";
		$content.= "From: ".$comment->get("from")."\n";
		$content.= "Created: ".$comment->get("created")."\n";
		if($comment->get("url")!="") $content.= "Url: ".$comment->get("url")."\n";
		$content.= "---\n";
		$content.= $comment->comment."\n";

		$fd = @fopen($file, "c");
		if($fd!==false)
		{
			flock($file, LOCK_EX);
			fseek($fd, 0, SEEK_END);
			$position = ftell($fd);
			if($position==0)
			{
				$template = file_get_contents($this->yellow->config->get("commentsTemplate"));
				if($template=="")
				{
					$template = "---\nTitle: Comments\nParser: Comments\n---\n";
				}
				fwrite($fd, $template);
				$position = ftell($fd);
			}
			if($position+strlen($content)<$this->yellow->config->get("commentsMaxSize"))
			{
				if($position>0) fwrite($fd, $this->yellow->config->get("commentsSeparator")."\n");
				fwrite($fd, $content);
			} else {
				$status = "Error";
			}
			flock($file, LOCK_UN);
			fclose($fd);
		} else {
			$status = "Error";
		}
		return $status;
	}

	// Build comment from input
	function buildComment()
	{
		$comment = new YellowComment;
		$comment->set("name", filter_var(trim($_REQUEST["name"]), FILTER_SANITIZE_STRING, FILTER_FLAG_STRIP_LOW));
		$comment->set("url", filter_var(trim($_REQUEST["url"]), FILTER_SANITIZE_STRING, FILTER_FLAG_STRIP_LOW));
		$comment->set("from", filter_var(trim($_REQUEST["from"]), FILTER_SANITIZE_STRING, FILTER_FLAG_STRIP_LOW));
		$comment->set("created", date("Y-m-d H:i:s"));
		$comment->set("uid", hash("sha256", $this->yellow->toolbox->createSalt(64)));
		$comment->comment = trim($_REQUEST["comment"]);
		return $comment;
	}

	// verify comment for safe use
	function verifyComment($comment)
	{
		// TODO: fold me :)
		$status = "send";
		$field = "";
		$spamFilter = $this->yellow->config->get("commentSpamFilter");
		if(strempty($comment->comment)) { $field = "comment"; $status = "InvalidComment"; }
		if(!strempty($comment->comment) && preg_match("/$spamFilter/i", $comment->comment)) { $field = "comment"; $status = "Error"; }
		if(!strempty($comment->get("name")) && preg_match("/[^\pL\d\-\. ]/u", $comment->get("name"))) { $field = "name"; $status = "InvalidName"; }
		if(!strempty($comment->get("from")) && !filter_var($comment->get("from"), FILTER_VALIDATE_EMAIL)) { $field = "from"; $status = "InvalidMail"; }
		if(!strempty($comment->get("from")) && preg_match("/[^\w\-\.\@ ]/", $comment->get("from"))) { $field = "from"; $status = "InvalidMail"; }
		if(!strempty($comment->get("url")) && !preg_match("/^https?\:\/\//i", $comment->get("url"))) { $field = "url"; $status = "InvalidUrl"; }

		$separator = $this->yellow->config->get("commentsSeparator");
		if(strpos($comment->comment, $separator)!==false) { $field = "comment"; $status = "InvalidComment"; }
		if(strpos($comment->get("name"), $separator)!==false) { $field = "name"; $status = "InvalidName"; }
		if(strpos($comment->get("from"), $separator)!==false) { $field = "from"; $status = "InvalidMail"; }
		if(strpos($comment->get("url"), $separator)!==false) { $field = "url"; $status = "InvalidUrl"; }
		$this->requiredField = $field;
		return $status;
	}

	// Process user input
	function processSend($page)
	{
		if(PHP_SAPI == "cli") $this->yellow->page->error(500, "Static website not supported!");
		$status = trim($_REQUEST["status"]);
		if($status == "send")
		{
			$comment = $this->buildComment();
			$status = $this->verifyComment($comment);
			if($status=="send" && $this->yellow->config->get("commentsAutoAppend")) $status = $this->appendComment($page, $comment);
			if($status=="send") $status = $this->sendEmail($comment);
			if($status=="done")
			{
				$this->yellow->page->set("commentsStatus", $this->yellow->text->get("commentsStatusDone"));
			} else {
				$this->yellow->page->set("commentsStatus", $this->yellow->text->get("commentsStatus".$status));
				$status = "invalid";
			}
			$this->yellow->page->setHeader("Last-Modified", $this->yellow->toolbox->getHttpDateFormatted(time()));
			$this->yellow->page->setHeader("Cache-Control", "no-cache, must-revalidate");
		} else {
			$status = "none";
			$this->yellow->page->set("commentsStatus", $this->yellow->text->get("commentsStatusNone"));
		}
		$this->yellow->page->set("status", $status);
	}
	
	// Send comment email
	function sendEmail($comment)
	{
		$mailMessage = $comment->comment."\r\n";
		$mailMessage.= "-- \r\n";
		$mailMessage.= "Name: ".$comment->get("name")."\r\n";
		$mailMessage.= "Mail: ".$comment->get("from")."\r\n";
		$mailMessage.= "Url:  ".$comment->get("url")."\r\n";
		$mailMessage.= "Uid:  ".$comment->get("uid")."\r\n";
		$mailTo = $this->yellow->page->get("commentEmail");
		if($this->yellow->config->isExisting("commentEmail")) $mailTo = $this->yellow->config->get("commentEmail");
		$mailSubject = mb_encode_mimeheader($this->yellow->page->get("title"));
		$mailHeaders = empty($from) ? "From: noreply\r\n" : "From: ".mb_encode_mimeheader($name)." <$from>\r\n";
		$mailHeaders .= "X-Contact-Url: ".mb_encode_mimeheader($this->yellow->page->getUrl())."\r\n";
		$mailHeaders .= "X-Remote-Addr: ".mb_encode_mimeheader($_SERVER["REMOTE_ADDR"])."\r\n";
		$mailHeaders .= "Mime-Version: 1.0\r\n";
		$mailHeaders .= "Content-Type: text/plain; charset=utf-8\r\n";
		return mail($mailTo, $mailSubject, $mailMessage, $mailHeaders) ? "done" : "Error";
	}

	// Return number of visible comments
	function getCommentCount()
	{
		$count = 0;
		foreach($this->comments as $comment)
		{
			if($comment->isPublished())
			{
				$count++;
			}
		}
		return $count;
	}
	
	// Return default string if field is required by name otherwise an empty string
	function required($field, $default)
	{
		return ($this->requiredField==$field)?$default:"";
	}
} 

$yellow->plugins->register("Comments", "YellowComments", YellowComments::Version);
?>
