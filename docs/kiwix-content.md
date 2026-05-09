# Kiwix Content

ZIM files downloaded for offline reference. All content is served via `kiwix-serve` on port 8888.

## Content List

| Book | Catalog Name | Est. Size | Purpose |
|---|---|---|---|
| Wikipedia EN (full, images) | `wikipedia_en_all_maxi` | ~100GB | General reference |
| WikiMed | `wikimed_en_all_maxi` | ~3GB | Medical encyclopedia |
| WikiHow | `wikihow_en_maxi` | ~10GB | Step-by-step how-to guides |
| iFixit | `ifixit_en_all` | ~5GB | Repair guides for electronics & appliances |
| Wikibooks | `wikibooks_en_all_maxi` | ~5GB | Free textbooks — mechanics, cooking, engineering |
| Stack Overflow | `stackoverflow.com_en_all` | ~34GB | Tech troubleshooting |
| Wikivoyage | `wikivoyage_en_all_maxi` | ~1GB | Geography, infrastructure, regional info |
| Khan Academy | `khan_academy_en_all` | ~30GB | Education — math, science, medicine |
| Project Gutenberg | `gutenberg_en_all` | ~60GB | 70k+ books, classics, reference |
| SE: Home Improvement | `home.stackexchange.com_en_all` | ~2GB | Repairs, construction, utilities |
| SE: Cooking | `cooking.stackexchange.com_en_all` | ~1GB | Food prep, preservation |
| SE: Amateur Radio | `ham.stackexchange.com_en_all` | ~500MB | Emergency communications |
| Wiktionary EN | `wiktionary_en_all_maxi` | ~5GB | Dictionary and language reference |

**Estimated total: ~257GB**
**Allocated on drive: ~570GB** (remaining space reserved for Nextcloud uploads)

## Downloading

Run the download script from the repo root:

```bash
sudo bash scripts/download-zim.sh /mnt/noosphere/kiwix
```

- Requires `wget` (default) or `aria2c` (faster, parallel)
- All downloads are resumable — safe to interrupt and restart
- Script skips files that already exist
- Resolves current URLs automatically from the Kiwix catalog API

## Verifying Catalog Names

If a download fails to resolve, check the current catalog:

```bash
curl "https://library.kiwix.org/catalog/v2/entries?lang=eng" | grep -o 'name="[^"]*"' | sort | uniq
```

Or browse: https://library.kiwix.org