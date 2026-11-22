<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>RFL Store | Shop Now</title>

    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: Arial, sans-serif;
            background: #f8fafc;
            color: #1e293b;
        }

        header {
            background: #fff;
            padding: 18px 6%;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 10px rgba(0, 0, 0, .05);
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .logo {
            font-size: 24px;
            font-weight: bold;
            color: #15803d;
        }

        .hero {
            padding: 65px 6%;
            background: linear-gradient(120deg, #14532d, #16a34a);
            color: white;
            text-align: center;
        }

        .hero h1 {
            font-size: 42px;
            margin-bottom: 12px;
        }

        .hero p {
            color: #dcfce7;
        }

        .container {
            max-width: 1400px;
            margin: auto;
            padding: 35px 6%;
        }

        .toolbar {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 30px;
        }

        input,
        select {
            padding: 12px 15px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            background: white;
            font-size: 14px;
        }

        input {
            flex: 1;
            min-width: 200px;
        }

        select {
            min-width: 180px;
        }

        .section-heading {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .product-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 22px;
        }

        .product-card {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            border: 1px solid #e2e8f0;
            transition: .2s;
        }

        .product-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, .08);
        }

        .product-image {
            height: 220px;
            width: 100%;
            object-fit: cover;
            background: #f1f5f9;
        }

        .product-placeholder {
            height: 220px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f1f5f9;
            color: #94a3b8;
        }

        .product-info {
            padding: 16px;
        }

        .product-brand {
            display: block;
            color: #64748b;
            font-size: 12px;
            margin-bottom: 8px;
        }

        .product-info h3 {
            font-size: 17px;
            margin-bottom: 8px;
        }

        .description {
            font-size: 13px;
            color: #64748b;
            line-height: 1.5;
            height: 40px;
            overflow: hidden;
        }

        .price {
            margin: 14px 0;
            font-weight: bold;
            font-size: 19px;
            color: #15803d;
        }

        .stock {
            font-size: 13px;
            margin-bottom: 12px;
        }

        .in-stock {
            color: #15803d;
        }

        .out-stock {
            color: #dc2626;
        }

        button {
            border: none;
            cursor: pointer;
            border-radius: 7px;
            padding: 11px 15px;
            background: #15803d;
            color: white;
            font-weight: 600;
        }

        button:hover {
            background: #166534;
        }

        button:disabled {
            background: #94a3b8;
            cursor: not-allowed;
        }

        .view-btn {
            width: 100%;
        }

        .message {
            text-align: center;
            padding: 50px 10px;
            color: #64748b;
            grid-column: 1 / -1;
        }

        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 15px;
            margin-top: 30px;
        }

        .pagination span {
            font-size: 14px;
        }

        .modal {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, .6);
            z-index: 100;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .modal-content {
            background: white;
            border-radius: 12px;
            padding: 25px;
            width: 100%;
            max-width: 600px;
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .close-btn {
            background: #e2e8f0;
            color: #334155;
        }

        .close-btn:hover {
            background: #cbd5e1;
        }

        .detail-image {
            width: 100%;
            max-height: 300px;
            object-fit: contain;
            margin-bottom: 15px;
        }

        .variant-card {
            padding: 12px;
            border: 1px solid #e2e8f0;
            margin-bottom: 8px;
            border-radius: 8px;
        }

        footer {
            margin-top: 60px;
            padding: 30px;
            text-align: center;
            background: #14532d;
            color: white;
        }

        @media(max-width: 1000px) {
            .product-grid {
                grid-template-columns: repeat(3, 1fr);
            }
        }

        @media(max-width: 700px) {
            .product-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .hero h1 {
                font-size: 30px;
            }
        }

        @media(max-width: 450px) {
            .product-grid {
                grid-template-columns: 1fr;
            }

            .section-heading {
                gap: 10px;
                flex-wrap: wrap;
            }
        }
    </style>
</head>

<body>

    <header>
        <div class="logo">RFL STORE</div>
        <div>Shop Online</div>
    </header>

    <section class="hero">
        <h1>Shop Our Products</h1>
        <p>Discover quality products for your everyday needs.</p>
    </section>

    <main class="container">

        <div class="toolbar">
            <input
                type="search"
                id="searchInput"
                placeholder="Search products...">

            <select id="brandFilter">
                <option value="">All Brands</option>
            </select>

            <select id="collectionFilter" disabled>
                <option value="">All Collections</option>
            </select>
        </div>

        <div class="section-heading">
            <h2>Our Products</h2>
            <span id="productCount">Loading...</span>
        </div>

        <div id="productGrid" class="product-grid">
            <div class="message">Loading products...</div>
        </div>

        <div id="pagination" class="pagination"></div>

    </main>

    <div id="productModal" class="modal">
        <div class="modal-content">

            <div class="modal-header">
                <h2>Product Details</h2>
                <button class="close-btn" onclick="closeModal()">Close</button>
            </div>

            <div id="productDetails"></div>

        </div>
    </div>

    <footer>
        <p>© {{ date('Y') }} RFL Store. All rights reserved.</p>
    </footer>

    <script>
        /*
        |--------------------------------------------------------------------------
        | API Configuration
        |--------------------------------------------------------------------------
        */

        const API_BASE = @json(url('/api/frontend'));

        /*
        |--------------------------------------------------------------------------
        | DOM Elements
        |--------------------------------------------------------------------------
        */

        const productGrid = document.getElementById('productGrid');
        const productCount = document.getElementById('productCount');
        const searchInput = document.getElementById('searchInput');
        const brandFilter = document.getElementById('brandFilter');
        const collectionFilter = document.getElementById('collectionFilter');
        const pagination = document.getElementById('pagination');

        /*
        |--------------------------------------------------------------------------
        | Application State
        |--------------------------------------------------------------------------
        */

        let products = [];
        let brands = [];
        let collections = [];

        let currentPage = 1;
        let lastPage = 1;
        let totalProducts = 0;

        /*
        |--------------------------------------------------------------------------
        | API Helpers
        |--------------------------------------------------------------------------
        */

        async function apiRequest(url) {
            const response = await fetch(url, {
                headers: {
                    'Accept': 'application/json'
                }
            });

            if (!response.ok) {
                throw new Error(`Request failed: ${response.status}`);
            }

            return await response.json();
        }

        function getItems(response) {
            if (Array.isArray(response)) {
                return response;
            }

            return response?.data ?? [];
        }

        function escapeHtml(value = '') {
            return String(value).replace(/[&<>"']/g, char => ({
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            })[char]);
        }

        /*
        |--------------------------------------------------------------------------
        | Product Helpers
        |--------------------------------------------------------------------------
        */

        function getImage(product) {
            const image = product.featured_image;

            if (typeof image === 'string') {
                return image;
            }

            if (image && typeof image === 'object') {
                return image.url ?? image.original_url ?? '';
            }

            return product.image_url ?? product.image ?? '';
        }

        function formatPrice(price) {
            if (price === null || price === undefined || price === '') {
                return 'Price unavailable';
            }

            return '৳' + Number(price).toLocaleString('en-BD', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        }

        /*
        |--------------------------------------------------------------------------
        | Load Brands
        |--------------------------------------------------------------------------
        */

        async function loadBrands() {
            try {
                const response = await apiRequest(`${API_BASE}/brands`);

                brands = getItems(response);

                brandFilter.innerHTML = '<option value="">All Brands</option>';

                brands.forEach(brand => {
                    const option = document.createElement('option');

                    option.value = brand.id;
                    option.textContent = brand.name;

                    brandFilter.appendChild(option);
                });

            } catch (error) {
                console.error('Brand loading error:', error);
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Load Collections By Brand
        |--------------------------------------------------------------------------
        */

        async function loadCollections(brandId) {
            collections = [];

            collectionFilter.innerHTML = `
                <option value="">All Collections</option>
            `;

            collectionFilter.disabled = !brandId;

            if (!brandId) {
                return;
            }

            try {
                const response = await apiRequest(
                    `${API_BASE}/brands/${encodeURIComponent(brandId)}/collections`
                );

                collections = getItems(response);

                collections.forEach(collection => {
                    const option = document.createElement('option');

                    option.value = collection.slug;
                    option.textContent = collection.name;

                    collectionFilter.appendChild(option);
                });

            } catch (error) {
                console.error('Collection loading error:', error);
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Load Products
        |--------------------------------------------------------------------------
        */

        async function loadProducts(page = 1) {
            try {
                productGrid.innerHTML = `
                    <div class="message">Loading products...</div>
                `;

                pagination.innerHTML = '';

                const response = await apiRequest(
                    `${API_BASE}/products?page=${page}`
                );

                products = getItems(response);

                currentPage = response.meta?.current_page ?? 1;
                lastPage = response.meta?.last_page ?? 1;
                totalProducts = response.meta?.total ?? products.length;

                productCount.textContent = `${totalProducts} Products`;

                filterProducts();

                renderPagination();

            } catch (error) {
                productGrid.innerHTML = `
                    <div class="message">
                        Unable to load products. Please try again.
                    </div>
                `;

                productCount.textContent = 'Products unavailable';

                console.error('Product loading error:', error);
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Render Products
        |--------------------------------------------------------------------------
        */

        function renderProduct(product) {
            const image = getImage(product);

            const imageHtml = image
                ? `
                    <img
                        class="product-image"
                        src="${escapeHtml(image)}"
                        alt="${escapeHtml(product.name)}"
                        loading="lazy"
                        onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">

                    <div class="product-placeholder" style="display:none">
                        No Image
                    </div>
                `
                : `
                    <div class="product-placeholder">
                        No Image
                    </div>
                `;

            return `
                <div class="product-card">

                    ${imageHtml}

                    <div class="product-info">

                        <small class="product-brand">
                            ${escapeHtml(product.brand?.name ?? '')}
                        </small>

                        <h3>${escapeHtml(product.name)}</h3>

                        <p class="description">
                            ${escapeHtml(product.collection?.name ?? '')}
                        </p>

                        <div class="price">
                            ${formatPrice(product.price_from)}
                        </div>

                        <p class="stock ${product.in_stock ? 'in-stock' : 'out-stock'}">
                            ${product.in_stock ? 'In Stock' : 'Out of Stock'}
                        </p>

                        <button
                            type="button"
                            class="view-btn"
                            data-slug="${escapeHtml(product.slug)}">
                            View Details
                        </button>

                    </div>
                </div>
            `;
        }

        function renderProducts(items) {
            if (!items.length) {
                productGrid.innerHTML = `
                    <div class="message">
                        No products found.
                    </div>
                `;

                return;
            }

            productGrid.innerHTML = items.map(renderProduct).join('');
        }

        /*
        |--------------------------------------------------------------------------
        | Client-Side Filtering
        |--------------------------------------------------------------------------
        |
        | The current products endpoint response contains brand and collection
        | names/slugs, but not their IDs. Therefore, filtering uses slugs.
        |
        | This filters products on the currently loaded API page.
        |
        */

        function filterProducts() {
            const search = searchInput.value.toLowerCase().trim();
            const selectedBrandId = brandFilter.value;
            const selectedCollectionSlug = collectionFilter.value;

            const selectedBrand = brands.find(
                brand => String(brand.id) === selectedBrandId
            );

            const filtered = products.filter(product => {
                const matchesSearch =
                    (product.name ?? '').toLowerCase().includes(search) ||
                    (product.description ?? '').toLowerCase().includes(search);

                const matchesBrand = !selectedBrand ||
                    product.brand?.slug === selectedBrand.slug;

                const matchesCollection = !selectedCollectionSlug ||
                    product.collection?.slug === selectedCollectionSlug;

                return matchesSearch && matchesBrand && matchesCollection;
            });

            renderProducts(filtered);
        }

        /*
        |--------------------------------------------------------------------------
        | Pagination
        |--------------------------------------------------------------------------
        */

        function renderPagination() {
            if (lastPage <= 1) {
                pagination.innerHTML = '';
                return;
            }

            pagination.innerHTML = `
                <button
                    type="button"
                    ${currentPage <= 1 ? 'disabled' : ''}
                    id="previousPage">
                    Previous
                </button>

                <span>Page ${currentPage} of ${lastPage}</span>

                <button
                    type="button"
                    ${currentPage >= lastPage ? 'disabled' : ''}
                    id="nextPage">
                    Next
                </button>
            `;
        }

        /*
        |--------------------------------------------------------------------------
        | Product Details
        |--------------------------------------------------------------------------
        */

        async function viewProduct(slug) {
            try {
                document.getElementById('productDetails').innerHTML = `
                    <div class="message">Loading product details...</div>
                `;

                document.getElementById('productModal').style.display = 'flex';

                const response = await apiRequest(
                    `${API_BASE}/products/${encodeURIComponent(slug)}`
                );

                const product = response.data ?? response;
                const variants = product.variants ?? [];
                const image = getImage(product);

                document.getElementById('productDetails').innerHTML = `
                    ${
                        image
                            ? `
                                <img
                                    class="detail-image"
                                    src="${escapeHtml(image)}"
                                    alt="${escapeHtml(product.name)}">
                            `
                            : ''
                    }

                    <h2>${escapeHtml(product.name)}</h2>

                    <p style="margin:12px 0;color:#64748b">
                        ${escapeHtml(product.description ?? '')}
                    </p>

                    <p>
                        Brand: ${escapeHtml(product.brand?.name ?? '-')}
                    </p>

                    <p>
                        Collection: ${escapeHtml(product.collection?.name ?? '-')}
                    </p>

                    <h3 style="margin:20px 0 12px">
                        Available Variants
                    </h3>

                    ${
                        variants.length
                            ? variants.map(variant => `
                                <div class="variant-card">

                                    <strong>
                                        ${escapeHtml(variant.title ?? variant.name ?? 'Variant')}
                                    </strong>

                                    <p>
                                        SKU: ${escapeHtml(variant.sku ?? '-')}
                                    </p>

                                    <p style="color:#15803d;font-weight:bold">
                                        ${formatPrice(variant.price)}
                                    </p>

                                </div>
                            `).join('')
                            : '<p>No variants available.</p>'
                    }
                `;

            } catch (error) {
                document.getElementById('productDetails').innerHTML = `
                    <p class="message">
                        Unable to load product details.
                    </p>
                `;

                console.error('Product details error:', error);
            }
        }

        function closeModal() {
            document.getElementById('productModal').style.display = 'none';
        }

        /*
        |--------------------------------------------------------------------------
        | Event Listeners
        |--------------------------------------------------------------------------
        */

        searchInput.addEventListener('input', filterProducts);

        brandFilter.addEventListener('change', async () => {
            await loadCollections(brandFilter.value);
            filterProducts();
        });

        collectionFilter.addEventListener('change', filterProducts);

        productGrid.addEventListener('click', event => {
            const button = event.target.closest('[data-slug]');

            if (button) {
                viewProduct(button.dataset.slug);
            }
        });

        pagination.addEventListener('click', event => {
            if (event.target.id === 'previousPage' && currentPage > 1) {
                loadProducts(currentPage - 1);
            }

            if (event.target.id === 'nextPage' && currentPage < lastPage) {
                loadProducts(currentPage + 1);
            }
        });

        document.getElementById('productModal').addEventListener('click', event => {
            if (event.target.id === 'productModal') {
                closeModal();
            }
        });

        /*
        |--------------------------------------------------------------------------
        | Initialize Shop
        |--------------------------------------------------------------------------
        */

        async function initializeShop() {
            await Promise.all([
                loadBrands(),
                loadProducts()
            ]);
        }

        initializeShop();
    </script>

</body>
</html>