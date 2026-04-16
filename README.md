# Remittance AR Collection Data Consolidator
Multi-pipeline CSV data processing system for Sales, Delcon, and Bank Customer datasets used in remittance and accounts receivable (AR) collection workflows.

## Quick Start (XAMPP)

1. Put this project in your XAMPP `htdocs` folder.
2. Start Apache from XAMPP Control Panel.
3. Open `http://localhost/csv-consolidator/public/` or `http://localhost/csv-consolidator/public/index.php`.
4. Choose your ingestion workflow:
- **Sales Tracking Masterfile**  
  → CSV → Database (with smart upsert + change tracking)
- **Delcon with SI Masterlist**  
  → CSV → Database (batched insert)
- **Bank Customer**  
  → Google Sheets → Database (API-based ingestion)

---

## Architecture

```
index.php                          # Router – client selector or dispatch
app/
  BaseApp.php                      # Shared: OutputSchema, HtmlEscaper, ClientRegistry, BaseApp
  Clients/
    Super8App.php                  # Super8 client logic (master data + PO parsing)
    OSaveApp.php                   # OSave client logic (placeholder)
    ShopifyApp.php                 # Shopify client logic (placeholder)
views/
  select_client.php                # Client picker landing page
  super8/home.php                  # Super8 UI
  osave/home.php                   # OSave UI
  shopify/home.php                 # Shopify UI

app/
  Config/
    Database.php                                      # PDO connection
    *.json                                            # Google Service credentials
  Controllers/
    CsvController.php                                 # Handles CSV upload, preview, import
    GoogleSheetBankCustomerController.php             # Handles Google Sheets ingestion
  Services/
    CsvParser.php                                     # CSV parsing logic
    GoogleSheetService.php                            # Fetch data from Google Sheets API
    BankCustomerService.php                           # Business logic for bank customer ingestion
    DatabaseMigration.php                             # Auto-run table creation/update
  Interfaces/
    CsvParserInterface.php
    GoogleSheetServiceInterface.php
views/
  home.php                                            # UI for CSV upload & preview
uploads/                                              # Temporary CSV storage
```
