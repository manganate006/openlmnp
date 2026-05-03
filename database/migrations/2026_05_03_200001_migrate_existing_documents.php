<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Migrer expenses.receipt_path vers documents
        $expenses = DB::table('expenses')->whereNotNull('receipt_path')->get();
        foreach ($expenses as $expense) {
            DB::table('documents')->insert([
                'documentable_type' => 'App\\Models\\Expense',
                'documentable_id'   => $expense->id,
                'label'             => 'Justificatif',
                'amount'            => null,
                'document_date'     => $expense->expense_date,
                'file_path'         => $expense->receipt_path,
                'sort_order'        => 0,
                'created_at'        => $expense->created_at,
                'updated_at'        => $expense->updated_at,
            ]);
        }

        // Migrer furniture.invoice_path vers documents
        $furniture = DB::table('furniture')->whereNotNull('invoice_path')->get();
        foreach ($furniture as $item) {
            DB::table('documents')->insert([
                'documentable_type' => 'App\\Models\\Furniture',
                'documentable_id'   => $item->id,
                'label'             => 'Facture',
                'amount'            => null,
                'document_date'     => $item->purchase_date,
                'file_path'         => $item->invoice_path,
                'sort_order'        => 0,
                'created_at'        => $item->created_at,
                'updated_at'        => $item->updated_at,
            ]);
        }

        // Supprimer les anciennes colonnes
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropColumn('receipt_path');
        });

        Schema::table('furniture', function (Blueprint $table) {
            $table->dropColumn('invoice_path');
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->string('receipt_path')->nullable()->after('is_dedicated');
        });

        Schema::table('furniture', function (Blueprint $table) {
            $table->string('invoice_path')->nullable()->after('is_second_hand');
        });

        // Restaurer les données
        $expenseDocs = DB::table('documents')
            ->where('documentable_type', 'App\\Models\\Expense')
            ->get();
        foreach ($expenseDocs as $doc) {
            DB::table('expenses')->where('id', $doc->documentable_id)
                ->update(['receipt_path' => $doc->file_path]);
        }

        $furnitureDocs = DB::table('documents')
            ->where('documentable_type', 'App\\Models\\Furniture')
            ->get();
        foreach ($furnitureDocs as $doc) {
            DB::table('furniture')->where('id', $doc->documentable_id)
                ->update(['invoice_path' => $doc->file_path]);
        }

        // Supprimer les documents migrés
        DB::table('documents')
            ->whereIn('documentable_type', ['App\\Models\\Expense', 'App\\Models\\Furniture'])
            ->where('label', 'Justificatif')
            ->orWhere('label', 'Facture')
            ->delete();
    }
};
