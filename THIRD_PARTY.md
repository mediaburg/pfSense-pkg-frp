# Bundled dependencies

These files are shipped locally; the firewall does not download runtime dependencies.

- **internal/toml 1.1.3**, BSD-3-Clause, commit `b0e077794a179b0a39a4fba84185762a4425c472`.
  Source: https://github.com/php-internal/toml/tree/b0e077794a179b0a39a4fba84185762a4425c472
  Unmodified `src/` and `LICENSE.md` are in `files/usr/local/pkg/frp/vendor/toml/`.
  Requires PHP 8.1+, with no additional runtime packages.
- **Ace 1.44.0**, BSD-3-Clause, commit `184177de1dcc5946b093edba0b0fe1c29c2a127a`.
  Source: https://github.com/ajaxorg/ace-builds/tree/184177de1dcc5946b093edba0b0fe1c29c2a127a
  Unmodified `src-min-noconflict/{ace,mode-toml,theme-textmate,ext-searchbox}.js`
  and `LICENSE` are in `files/usr/local/www/frp-assets/ace/`.

When updating, pin the upstream commit, retain the upstream license, rerun the
parser/GUI/integration tests, and regenerate `pkg-plist` using `tests/check.py --write-plist`.
