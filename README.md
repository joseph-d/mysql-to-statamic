# Convert MySQL table data to Statamic YAML collection entries

![Statamic 3.0+](https://img.shields.io/badge/Statamic-3.0+-FF269E?style=for-the-badge&link=https://statamic.com)

## What is this?

A console-based add-on for [Statamic 3.0+](https://github.com/statamic) which automates reading data from Laravel-style tables in a MySQL (or compatible) database and writing them as Statamic YAML collections. 

## Who is this for?

Useful for anyone who is planning to migrate a lot of data from a Laravel-style database (think `created_at`, `updated_at` fields etc.) into Statamic's flat-file YAML format. This could help migration from another Laravel-based CMS or even just a regular Laravel project that has got a bit out of hand and needs taming with Statamic.

## Features

* **Automatically scans the MySQL database** and presents a list of tables for you to choose from

* **Allows you to select field names** from the table for conversion

* **Converts regular image URLs to Statamic asset tags** so no further editing of entires is needed to get images to display properly

* **JSON field support** scans each field to determine if it contains JSON data. If it does, the data is saved in the YAML file in a way which will make blueprinting the replicator field straightforward.

## Assumptions made

This package will assume that you have the following:

1. A [Statamic 3.0+](https://github.com/statamic) project with collections (and preferably blueprints) already set up.
2. A source MySQL database configured in `config/database.php` or `.env` file with the following fields present:
    - `title` or `name` (string)
    - `slug` (string)
    - `published_at` or `created_at` (date)
    - `published` (boolean) - optional

## Installation

`composer require josephd/mysql-to-statamic`

## Usage

Run `php artisan mysql-to-statamic:run` 

Follow the prompts. The various options can also be entered directly onto the command line as follows:

`--table="table_name"`

The MySQL table name to set as the source

`--field="field1_name" --field="field2_name" ...`  

One for each field name you want to convert (`title` or `name`,`slug`,`published_at` or `created_at` will be done automatically)

`--collection="statamic_collection_handle"` 

The handle name of the destination Statamic collection.

`--image-prefix="https://www.example.com/img"`

Optional if you want to convert the prefix of image URLs into Statamic asset tags.

`--assetTag="asset::assets::"`

If you have set an image URL preview then you will also need to specify the asset tag that it should be replaced with.

`--confirm`

Include if you want the command to go ahead without giving a final confirmation.

## Possible to-dos

* Config to allow direct mapping of MySQL field names to Statamic YAML field names
* Graphical interface alternative to console
* Ability to enter SQL query directly in order to get related datasets
* Probably other things

## Thanks to

Inspired by [Statamic Data Import](https://github.com/riasvdv/statamic-data-import) by [Rias](https://rias.be), an add-on which converts CSV data into Statamic YAML collections.

## Feedback / comments

All feedback, comments and pull requests gratefully received.