# ETracker WordPress Plugin

ETracker brings MongoDB-backed server enforcement data into the WordPress admin so operations teams can review compliance posture, manage exceptions, and keep stakeholders in the loop.

## Features

- **Server Inventory Browser** – filter by hostname, application, ownership, or GVP to quickly find any managed host.
- **Detailed Enforcement View** – see the full CIS/Agents control matrix for each server with status pills, context metadata, and audit history.
- **Exception Lifecycle Management** – activate or retire exceptions, capture reasoning, approvers, expiration dates, and optional metadata notes.
- **Configurable Default Durations** – set the default exception lifespan (in days) from the ETracker settings screen.
- **Expiring Exceptions Report** – monitor exceptions approaching renewal and identify overdue items.
- **Unenforced Controls Report** – highlight controls that are currently marked “Not Enforced” with exception context.
- **Shortcode for Frontend/Dashboards** – embed the full inventory UI inside any protected page using `[etracker_inventory]`.

## Requirements

- WordPress 6.0+ with PHP 8.1 or newer.
- MongoDB instance containing the enforcement documents referenced by the plugin.
- A WordPress user role granted the `manage_etracker_exceptions` capability (administrators get it automatically).

## Installation

1. Download the latest ZIP from the [releases page](https://github.com/DroppedLink/Exception-Tracker/releases).
2. In WordPress, navigate to `Plugins → Add New → Upload Plugin` and upload `ETracker.zip`.
3. Activate the plugin.
4. Visit `ETracker → Settings` to provide the MongoDB connection details (URI, database, collection). You can also define constants `ETRACKER_MONGO_URI`, `ETRACKER_MONGO_DATABASE`, and `ETRACKER_MONGO_COLLECTION` if preferred.
5. Optionally set the default exception duration (in days).

## Usage

- **Admin Inventory:** Navigate to `ETracker → Inventory` to browse and update server enforcement records.
- **Reports:** Use `ETracker → Reports` to review upcoming exception renewals and unenforced controls. Filters let you adjust the expiring window and row caps.
- **Shortcode:** Place `[etracker_inventory]` on a page (visible only to users with the management capability) to surface the search/detail UI.
- **Audit Trail:** All exception changes are logged in the `wp_etracker_audit` table for accountability.

## Development

```
git clone https://github.com/DroppedLink/Exception-Tracker.git
cd Exception-Tracker
```

The plugin is organised under `includes/` for services, repositories, admin pages, and frontend components. CSS lives in `assets/css/etracker.css`.

## Contributing

Issues and pull requests are welcome. Please open an issue with your proposal before submitting large changes.

## License

GPLv2 or later. See [`LICENSE`](LICENSE) if provided by your deployment.

