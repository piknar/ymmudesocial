<?php
/**
 * Error page for social login failures.
 * Variables available: $siteName, $title, $message, $loginUrl (all pre-escaped except $loginUrl)
 */
defined('_JEXEC') or die;
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?php echo $title; ?> - <?php echo $siteName; ?></title>
<style>
body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#0a0a0f;color:#e5e7eb;font-family:"SF Mono","Fira Code","JetBrains Mono",monospace}
.box{max-width:520px;width:92%;padding:2rem;border-radius:12px;border:1px solid rgba(139,92,246,.45);background:rgba(20,20,32,.9);box-shadow:0 10px 40px rgba(0,0,0,.5)}
h1{margin:0 0 1rem;font-size:1.25rem;color:#ef4444}
h1::before{content:">_ ";color:#8b5cf6}
p{line-height:1.6;color:#c7c9d3}
a.btn{display:inline-block;margin-top:1.25rem;padding:.65rem 1.1rem;border-radius:8px;border:1px solid #06b6d4;color:#06b6d4;text-decoration:none;font-weight:600}
a.btn:hover{background:rgba(6,182,212,.12)}
</style>
</head>
<body>
<div class="box">
  <h1><?php echo $title; ?></h1>
  <p><?php echo $message; ?></p>
  <a class="btn" href="<?php echo htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8'); ?>">Back to login</a>
</div>
</body>
</html>
