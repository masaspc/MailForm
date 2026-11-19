#!/usr/bin/env bash
# Build a fictional theme from the checked stock copy; all custom content is generated here.
set -eu
parent=$1/resources/views/theme
dest=$parent/garden_example
[[ -d $parent/gradation && ! -e $dest ]]
cp -R "$parent/gradation" "$dest"
mkdir -p "$dest/images"
cat > "$dest/images/garden.svg" <<'SVG'
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 40 40"><title>Garden</title><circle cx="20" cy="20" r="18" fill="#407557"/></svg>
SVG
cat > "$dest/css/style.css" <<'CSS'
@import "https://example.invalid/old.css";
.site-banner { background-image: url("../images/./garden.svg"); color: #214631; }
CSS
cat > "$dest/header.blade.php" <<'HTML'
<p><img src="{{ $data->theme_path }}images/garden.svg" alt="Garden">Garden desk</p>
HTML
printf '%s\n' '// fictional local measurement' > "$dest/js/ad_cookie.js"
printf '\n%s\n' '// fictional old input helper' >> "$dest/js/jquery.emform.js"
printf '\n%s\n' '<!-- fictional form change -->' >> "$dest/form.blade.php"
printf '\n%s\n' '<!-- fictional email change -->' >> "$dest/reply_mail.blade.php"
printf '%s\n' '// source only' > "$dest/css/notes.scss"
