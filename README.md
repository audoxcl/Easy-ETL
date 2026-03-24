# HubSpot Easy ETL

A lightweight PHP script to extract records from the HubSpot API and export them as flat CSV files — with optional database caching for large datasets.

---

## What it does

This script acts as a self-hosted ETL layer between HubSpot and any downstream tool (spreadsheets, data warehouses, BI platforms, etc.). It:

1. Fetches all property definitions for a given HubSpot object (contacts, deals, companies, etc.)
2. Pulls all records from HubSpot in batches via a connector service
3. Caches records in a local MySQL database to handle large volumes
4. Exports a clean CSV file with one row per record and all properties as columns
5. Optionally exports a second CSV with all associations (e.g. contact → deal relationships)

---

## Architecture

```
HubSpot API
    │
    ▼
HubSpotConnector (external relay)
    │
    ▼
index.php  ──►  MySQL (connectors table)  ──►  {object}.csv
                                           ──►  {object}_associations.csv
```

The script is designed to run either as an **HTTP endpoint** (called by a webhook or scheduler) or directly from the **command line**.

---

## Requirements

- PHP 7.4 or higher with `curl` and `pdo_mysql` extensions
- MySQL / MariaDB database
- A running instance of [HubSpotConnector](https://github.com/) (the relay service that authenticates with HubSpot)
- A HubSpot API key or private app token

---

## Installation

```bash
git clone https://github.com/your-username/hubspot-easy-etl.git
cd hubspot-easy-etl
cp config.example.php config.php
```

Edit `config.php` with your credentials:

```php
$config = [
    'hapikey' => 'your-hubspot-api-key',
    'db' => [
        'user'     => 'your-db-user',
        'password' => 'your-db-password',
    ],
    'HubSpotConnector' => [
        'url' => 'https://your-connector-url.com',
    ],
];
```

Create the database table:

```sql
CREATE TABLE connectors (
    id          VARCHAR(36) PRIMARY KEY,
    service     VARCHAR(100),
    software    VARCHAR(100),
    object      VARCHAR(100),
    object_id   VARCHAR(100),
    data        JSON,
    status      VARCHAR(50),
    UNIQUE KEY unique_record (service, software, object, object_id)
);
```

---

## Usage

### Via HTTP

```
GET /index.php?action=getHubSpotRecords&object=contacts&service=my_service
```

Requires an `Authorization: Bearer <token>` header. Valid tokens are defined in `functions.php`.

### Via CLI

```bash
php index.php getHubSpotRecords contacts '["firstname","email"]' "companies,deals"
```

Arguments in order: `action`, `object`, `properties`, `associations`

---

## Output files

| File | Description |
|---|---|
| `{object}.csv` | One row per HubSpot record, all properties as columns |
| `{object}_associations.csv` | Flat list of all associations: `id`, `association`, `association_id`, `association_type` |

---

## Authentication

HTTP requests must include a bearer token:

```
Authorization: Bearer TOKEN1
```

Tokens are validated in `functions.php` against a hardcoded allowlist. For production use, replace this with a more robust authentication mechanism (database-backed tokens, JWT, etc.).

---

## Logging

All operations are logged to `log.txt` in the project root, including timestamps, process IDs, memory usage, and record counts. Useful for monitoring long-running exports.

---

## Configuration reference

| Key | Description |
|---|---|
| `hapikey` | HubSpot API key passed to the connector |
| `db.user` | MySQL username |
| `db.password` | MySQL password |
| `HubSpotConnector.url` | URL of the HubSpotConnector relay service |

---

## Limitations & notes

- Properties are fetched in batches of 50 to stay within URL length limits
- Records are processed in batches of 1,000 from the local DB to control memory usage
- The script deletes existing records for the object before re-fetching (full refresh, not incremental)
- Semicolons are stripped from property values to prevent CSV formatting issues
- The `status` column (`Todo` → `In Progress` → `Done`) allows resuming interrupted exports without reprocessing completed records

---

## File structure

```
hubspot-easy-etl/
├── index.php          # Main ETL logic
├── functions.php      # Auth and logging helpers
├── config.php         # Credentials and settings (not committed)
├── config.example.php # Config template
└── log.txt            # Runtime log (auto-generated)
```

---

## Contact Us:

- info@audox.com
- www.audox.com
- www.audox.mx
- www.audox.es
- www.audox.cl
