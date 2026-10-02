<!DOCTYPE html>
<html lang="bs">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} | Geovizija</title>
    <meta name="description" content="{{ $description }}">
    <link rel="canonical" href="{{ $articleUrl }}">
    <meta property="og:type" content="article">
    <meta property="og:site_name" content="Geovizija">
    <meta property="og:locale" content="bs_BA">
    <meta property="og:title" content="{{ $title }}">
    <meta property="og:description" content="{{ $description }}">
    <meta property="og:url" content="{{ $shareUrl }}">
    @if ($image)
        <meta property="og:image" content="{{ $image }}">
        <meta property="og:image:width" content="1600">
        <meta property="og:image:height" content="900">
    @endif
    <meta name="twitter:card" content="summary_large_image">
    <meta http-equiv="refresh" content="0; url={{ $articleUrl }}">
    <script>location.replace(@json($articleUrl));</script>
</head>
<body style="font-family: sans-serif; background: #0c0a09; color: #f5f5f4; padding: 2rem;">
    <p><a href="{{ $articleUrl }}" style="color: #10b981;">{{ $title }}</a></p>
</body>
</html>
