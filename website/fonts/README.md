# Fonts

Self-hosted so the page makes no request to a third party. A Google Fonts stylesheet
sends every visitor's IP address to Google before the page renders, which is what the
Munich Regional Court decided against in 2022 (3 O 17493/20).

The three files are the **latin** subsets Google Fonts serves, downloaded once:

| File | Family | Weights |
|---|---|---|
| `caveat.woff2` | Caveat | 600 |
| `open-sans.woff2` | Open Sans | 400–800, variable |
| `source-code-pro.woff2` | Source Code Pro | 400–600, variable |

All three are licensed under the SIL Open Font License 1.1 (`OFL.txt`), which permits
self-hosting. Copyright is held by their respective authors: Caveat by Impallari Type,
Open Sans by Steve Matteson, Source Code Pro by Adobe.

To refresh them, ask the Google Fonts CSS API for the same families with a browser
user agent, take the `src` URL of each `/* latin */` block, and download those files.
