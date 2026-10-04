# Changelog

All notable changes to this extension are documented here. The format
is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/).

## [1.0.16] - 2026-10-04

### Fixed
- Sort order overrides are now ranked against the stock sort orders of the other items in the same level, so an overridden item lands where its number says (Dashboard set to 95 now shows after System at 80) instead of always jumping ahead of every unchanged item.
- Quick search lists only matching rows, also when their parent row is collapsed.
- Stores, Stores Settings and Configuration rows are shown as Locked with an explanation and their parent picker is disabled; a save that tries to switch them off or move them is refused with an error message. The Configuration entry is now matched by its real menu ID (Magento_Config::system_config), so it can no longer be hidden or moved.
- The parent picker no longer offers the row's own sub-items, and the server refuses a parent that would place an item under itself or one of its sub-items. The parent hint no longer mentions drag and drop.
- New, Rename and Duplicate view prompts keep the dialog open with a message when the name is empty; the success messages show the cleaned view name.
- Below 1280px the ID column is folded under each item title, so the label, icon and parent columns stay readable at 1024px.
