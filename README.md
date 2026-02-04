# Lead Scrap Software

Lead management app with scrapper sheets, Excel-style data entry, and sales workflows.

## Features

- Role-based access: admin, front_sale, upsale, scrapper.
- Scrapper sheets with inline grid editing (auto-save).
- Sales notifications for new leads.
- Lead comments (sales team only).
- Custom lead statuses:
  - Wrong Number
  - Follow Up
  - Hired Us
  - Hired Someone
  - No Response

## Requirements

- PHP 8.2+
- Composer
- Node.js + npm
- MySQL

## Setup

1) Install dependencies:

```bash
composer install
npm install
```

2) Configure environment:

```bash
copy .env.example .env
php artisan key:generate
```

3) Set database credentials in `.env`, then run:

```bash
php artisan migrate
php artisan db:seed
```

4) Run the app:

```bash
php artisan serve
npm run dev
```

## Usage Notes

- Scrapper creates a sheet and adds leads in the sheet grid.
- Sales team (front_sale, upsale) and admin view leads and update statuses.
- Sales team can add comments in the lead detail view.

## Status Migration

If you are upgrading from older statuses, run the status migration:

```bash
php artisan migrate
```

It remaps old values to the new set.
