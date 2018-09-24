Phan Prototypes
===============

Ideas for Phan that aren't ready for inclusion in Phan

Plugins
-------

### SCCP Plugin

[plugins/sccp.php](plugins/sccp.php) contains a prototype of a plugin warning about blocks of code that are giant no-ops. https://github.com/phan/phan/issues/1145

PHP's Opcache has SCCP (Sparse Constant Conditional Propogation) and DCE (Dead Code Elimination), which are improved in 7.2 and 7.3.

- This plugin works, but uses heuristics that may change in future PHP versions.
  Parsing the opcache text output is fragile.
- In practice, this plugin doesn't detect many bugs, because code usually doesn't have that type of bug
- This plugin is also very slow. Adding caching might help with that.
