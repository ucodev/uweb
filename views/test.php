<!DOCTYPE html>
<html>
	<head>
		<link rel="stylesheet" href="<?=static_css_url()?>/main.css" />
		<script src="<?=static_js_url()?>/test.js"></script>
		<title>Dummy View</title>
	</head>
	<body>
		<h1>Model Output</h1>
		<p><?=$model_output?></p>
		<h1>Database Output</h2>
		<p><?=$database_output?></p>
		<h1>Session Output</h1>
		<p><?=$session_output?></p>
		<h1>Directories</h1>
		<p>
			<strong>Base Directory:</strong> <?=base_dir()?>
		</p>
		<p>
			<strong>Base URL:</strong> <?=base_url()?>
		</p>
		<script type="text/javascript">
			test_js();
		</script>
	</body>
</html>