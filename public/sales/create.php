<?php
require_once '../../config/db.php';
require_once '../../templates/header.php';

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!isset($_POST['items']) || !is_array($_POST['items'])) {
            throw new Exception("Tidak ada item yang dikirim");
        }

        $pdo->beginTransaction();

        // Buat invoice number unik
        do {
            $invoice_number = 'INV-' . date('Ym') . '-' . str_pad(random_int(1, 999), 3, '0', STR_PAD_LEFT);
            $check = $pdo->prepare("SELECT COUNT(*) FROM sales WHERE invoice_number = ?");
            $check->execute([$invoice_number]);
        } while ($check->fetchColumn() > 0);

        // Simpan header
        $stmt = $pdo->prepare("INSERT INTO sales (invoice_number, customer_id, total_amount, total_profit, payment_method, sale_date) VALUES (?, ?, ?, ?, ?, NOW())");
        $stmt->execute([
            $invoice_number,
            $_POST['customer_id'] ?: null,
            0,
            0,
            $_POST['payment_method']
        ]);

        $sale_id = $pdo->lastInsertId();
        $total_amount = 0;
        $total_profit = 0;

        // Proses item
        foreach ($_POST['items'] as $item) {
            if (empty($item['product_id']) || empty($item['quantity']) || (int)$item['quantity'] <= 0) {
                throw new Exception("Data item tidak valid");
            }

            $product_stmt = $pdo->prepare("SELECT cost_price, sale_price, stock FROM products WHERE product_id = ? FOR UPDATE");
            $product_stmt->execute([$item['product_id']]);
            $product = $product_stmt->fetch();

            if (!$product || $product['stock'] < $item['quantity']) {
                throw new Exception("Stok tidak cukup untuk produk ID: " . $item['product_id']);
            }

            $subtotal = $product['sale_price'] * $item['quantity'];
            $profit = ($product['sale_price'] - $product['cost_price']) * $item['quantity'];

            $item_stmt = $pdo->prepare("INSERT INTO sale_items (sale_id, product_id, quantity, cost_price, sale_price) VALUES (?, ?, ?, ?, ?)");
            $item_stmt->execute([
                $sale_id,
                $item['product_id'],
                $item['quantity'],
                $product['cost_price'],
                $product['sale_price']
            ]);

            $total_amount += $subtotal;
            $total_profit += $profit;

            $update_stmt = $pdo->prepare("UPDATE products SET stock = stock - ? WHERE product_id = ?");
            $update_stmt->execute([$item['quantity'], $item['product_id']]);
        }

        // Update total
        $update_sale = $pdo->prepare("UPDATE sales SET total_amount = ?, total_profit = ? WHERE sale_id = ?");
        $update_sale->execute([$total_amount, $total_profit, $sale_id]);

        $pdo->commit();
        header("Location: index.php?success=1");
        exit;
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = $e->getMessage();
    }
}

$products = $pdo->query("SELECT * FROM products WHERE stock > 0")->fetchAll();
$customers = $pdo->query("SELECT * FROM customers")->fetchAll();
?>

<div class="container mx-auto px-4 py-8">
    <h1 class="text-2xl font-bold mb-6">Transaksi Penjualan Baru</h1>

    <?php if ($error): ?>
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <form method="POST" class="bg-white p-6 rounded shadow-md">
        <div class="mb-4">
            <label class="block text-gray-700 mb-2">Pelanggan</label>
            <select name="customer_id" class="w-full p-2 border rounded">
                <option value="">Pilih Pelanggan</option>
                <?php foreach ($customers as $customer): ?>
                    <option value="<?= $customer['customer_id'] ?>">
                        <?= htmlspecialchars($customer['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="mb-4">
            <label class="block text-gray-700 mb-2">Metode Pembayaran</label>
            <select name="payment_method" class="w-full p-2 border rounded" required>
                <option value="Cash">Cash</option>
                <option value="Transfer">Transfer</option>
                <option value="QRIS">QRIS</option>
                <option value="Kredit">Kredit</option>
            </select>
        </div>

        <div class="mb-6">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-semibold">Item Penjualan</h3>
                <button type="button" onclick="addItemRow()"
                    class="bg-blue-500 text-white px-4 py-2 rounded hover:bg-blue-600">
                    Tambah Item
                </button>
            </div>

            <div id="items-container">
                <div class="item-row flex gap-4 mb-4">
                    <select name="items[0][product_id]" required class="flex-1 p-2 border rounded">
                        <option value="">Pilih Produk</option>
                        <?php foreach ($products as $product): ?>
                            <option value="<?= $product['product_id'] ?>">
                                <?= htmlspecialchars($product['name']) ?> (Stok: <?= $product['stock'] ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <input type="number" name="items[0][quantity]" min="1" class="w-24 p-2 border rounded" placeholder="Qty" required>
                    <button type="button" onclick="this.parentElement.remove()"
                        class="bg-red-500 text-white px-3 py-2 rounded hover:bg-red-600">
                        Hapus
                    </button>
                </div>
            </div>
        </div>

        <button type="submit" class="bg-green-500 text-white px-6 py-2 rounded hover:bg-green-600">
            Simpan Transaksi
        </button>
    </form>
</div>

<script>
    let itemCount = 1;

    function addItemRow() {
        const container = document.getElementById('items-container');
        const newRow = document.createElement('div');
        newRow.className = 'item-row flex gap-4 mb-4';
        newRow.innerHTML = `
            <select name="items[${itemCount}][product_id]" required class="flex-1 p-2 border rounded">
                <option value="">Pilih Produk</option>
                <?php foreach ($products as $product): ?>
                    <option value="<?= $product['product_id'] ?>">
                        <?= htmlspecialchars($product['name']) ?> (Stok: <?= $product['stock'] ?>)
                    </option>
                <?php endforeach; ?>
            </select>
            <input type="number" name="items[${itemCount}][quantity]" min="1" class="w-24 p-2 border rounded" placeholder="Qty" required>
            <button type="button" onclick="this.parentElement.remove()" class="bg-red-500 text-white px-3 py-2 rounded hover:bg-red-600">Hapus</button>
        `;
        container.appendChild(newRow);
        itemCount++;
    }
</script>

<?php require_once '../../templates/footer.php'; ?>