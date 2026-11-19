#!/usr/bin/env bash
# Build a fictional theme from the checked stock copy; all custom content is generated here.
set -eu
parent=$1/resources/views/theme
dest=$parent/garden_example
[[ -d $parent/gradation && ! -e $dest ]]
cp -R "$parent/gradation" "$dest"
mkdir -p "$dest/images"
cat > "$dest/images/logo_main.svg" <<'SVG'
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 40 40">
  <title>Garden</title>
  <defs><linearGradient id="若葉"><stop offset="0" stop-color="#407557"/></linearGradient></defs>
  <g id="グループ_8" data-name="グループ 8"><circle cx="20" cy="20" r="18" fill="url(#若葉)"/></g>
</svg>
SVG
cat > "$dest/css/style.css" <<'CSS'
@import "https://example.invalid/old.css";
.site-banner {
  color: #214631;
  display: -webkit-box;
  display: -moz-inline-box;
  -webkit-box-sizing: border-box;
  -webkit-transform: translateX(2px);
  -webkit-box-pack: center;
  -ms-flex-pack: center;
}
#logo a { display: block; width: 180px; height: 36px; background-image: url(../images/logo_main.svg); text-indent: 100%; overflow: hidden; }
@media (max-width: 30em) { #logo a { width: 120px; height: 24px; } }
input { -webkit-appearance: none; -webkit-text-size-adjust: 100%; ime-mode: auto; }
textarea { ime-mode: auto; font-feature-setting: normal; text-justify: auto; }
select::-ms-expand, p::-moz-selection { color: red; }
.unused { background: transparent no-scroll; }
@media (-webkit-min-device-pixel-ratio: 2), (min-resolution: 2dppx) {
  .site-banner { border-width: 2px; }
}
CSS
cat > "$dest/header.blade.php" <<'HTML'
<p id="logo"><a href="https://garden.example.test/">Garden desk</a></p>
HTML
printf '%s\n' '// fictional local measurement' > "$dest/js/ad_cookie.js"
printf '\n%s\n' '// fictional old input helper' >> "$dest/js/jquery.emform.js"
cp "$(dirname "$0")"/theme-structure/*.blade.php "$dest/"
cat >> "$dest/css/style.css" <<'CSS'
.orchard-pair { display: grid; grid-template-columns: 1fr 2fr; }
.orchard-key { font-weight: bold; }
.orchard-picker > .orchard-select { border: 2px solid green; }
.orchard-actions > div > button { padding: 12px; }
@media (max-width: 40em) { .orchard-pair { display: block; } }
CSS
printf '\n%s\n' '<!-- fictional email change -->' >> "$dest/reply_mail.blade.php"
printf '%s\n' '// source only' > "$dest/css/notes.scss"
