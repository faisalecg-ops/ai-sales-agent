# Build Asia blog starter content — sources

`build_pack.py` turns the article files into `blog/content-packs/buildasia/`
(pack.json + images). `covers.py` draws the branded cover images with Pillow
using Archivo (static instances made from the blog's variable font with
fontTools). Facts in each article come only from the sources listed in it
(opened 9 October 2026) or from the sales agent's approved Build Asia
knowledge (`tools/load-buildasia-content.php`). To add or correct an article,
edit the article file, run `python3 covers.py && python3 build_pack.py`, and
rebuild the blog package.
