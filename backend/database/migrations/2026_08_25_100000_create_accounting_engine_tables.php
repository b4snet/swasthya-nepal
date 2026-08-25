<?php

use IlluminateDatabaseMigrationsMigration;
use IlluminateDatabaseSchemaBlueprint;
use IlluminateSupportFacadesSchema;

return new class extends Migration
{
    public function up(): void
    {
        // Chart of Accounts
        Schema::create("accounts", function (Blueprint $t) {
            $t->uuid("id")->primary();
            $t->uuid("tenant_id")->index();
            $t->uuid("facility_id")->nullable()->index();
            $t->string("code", 20);
            $t->string("name");
            $t->string("type"); // asset, liability, equity, revenue, expense
            $t->string("category")->nullable();
            $t->uuid("parent_id")->nullable();
            $t->string("reporting_category")->nullable();
            $t->text("description")->nullable();
            $t->boolean("is_cash_account")->default(false);
            $t->boolean("is_bank_account")->default(false);
            $t->date("effective_from")->nullable();
            $t->date("effective_to")->nullable();
            $t->string("status")->default("active");
            $t->integer("lock_version")->default(0);
            $t->uuid("created_by")->nullable();
            $t->uuid("updated_by")->nullable();
            $t->timestamps();
            $t->unique(["tenant_id", "code"]);
        });

        // Journal Entries
        Schema::create("journal_entries", function (Blueprint $t) {
            $t->uuid("id")->primary();
            $t->uuid("tenant_id")->index();
            $t->uuid("facility_id")->nullable()->index();
            $t->string("entry_number");
            $t->date("entry_date");
            $t->uuid("period_id")->nullable()->index();
            $t->string("source_type")->nullable();
            $t->uuid("source_id")->nullable();
            $t->text("description");
            $t->string("reference")->nullable();
            $t->string("status")->default("draft"); // draft, reviewed, posted, reversed
            $t->timestamp("posted_at")->nullable();
            $t->uuid("posted_by")->nullable();
            $t->uuid("reversed_by_entry_id")->nullable();
            $t->integer("lock_version")->default(0);
            $t->uuid("created_by")->nullable();
            $t->uuid("updated_by")->nullable();
            $t->timestamps();
            $t->unique(["tenant_id", "entry_number"]);
        });

        // Journal Lines
        Schema::create("journal_lines", function (Blueprint $t) {
            $t->uuid("id")->primary();
            $t->uuid("tenant_id")->index();
            $t->uuid("facility_id")->nullable()->index();
            $t->uuid("journal_entry_id")->index();
            $t->uuid("account_id")->index();
            $t->bigInteger("debit_minor")->default(0);
            $t->bigInteger("credit_minor")->default(0);
            $t->text("description")->nullable();
            $t->uuid("patient_id")->nullable();
            $t->uuid("invoice_id")->nullable();
            $t->uuid("payment_id")->nullable();
            $t->uuid("claim_id")->nullable();
            $t->uuid("created_by")->nullable();
            $t->timestamps();
            $t->foreign("journal_entry_id")->references("id")->on("journal_entries");
            $t->foreign("account_id")->references("id")->on("accounts");
        });

        // Accounts Payable
        Schema::create("accounts_payable", function (Blueprint $t) {
            $t->uuid("id")->primary();
            $t->uuid("tenant_id")->index();
            $t->uuid("facility_id")->nullable()->index();
            $t->uuid("supplier_id")->index();
            $t->uuid("purchase_order_id")->nullable();
            $t->uuid("goods_receipt_id")->nullable();
            $t->string("invoice_number");
            $t->date("invoice_date");
            $t->date("due_date");
            $t->bigInteger("total_minor");
            $t->bigInteger("tax_minor")->default(0);
            $t->bigInteger("paid_minor")->default(0);
            $t->string("status")->default("draft");
            $t->uuid("approved_by")->nullable();
            $t->timestamp("approved_at")->nullable();
            $t->string("payment_reference")->nullable();
            $t->timestamp("paid_at")->nullable();
            $t->text("notes")->nullable();
            $t->integer("lock_version")->default(0);
            $t->uuid("created_by")->nullable();
            $t->uuid("updated_by")->nullable();
            $t->timestamps();
            $t->foreign("supplier_id")->references("id")->on("suppliers");
        });
    }

    public function down(): void
    {
        Schema::dropIfExists("accounts_payable");
        Schema::dropIfExists("journal_lines");
        Schema::dropIfExists("journal_entries");
        Schema::dropIfExists("accounts");
    }
};