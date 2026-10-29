var cart = [];
var catalog = Object.create(null);

function formatRand(amount) {
  return 'R ' + amount.toLocaleString('en-ZA');
}

function addToCart(productId, category) {
  var product = catalog[productId];
  if (!product) {
    return;
  }
  var existing = cart.find(function(item) { return item.id === productId; });
  if (existing) {
    existing.qty++;
  } else {
    cart.push({
      id: productId,
      name: product.name,
      price: product.price,
      cat: category,
      image: product.src,
      alt: product.alt,
      qty: 1
    });
  }
  openCart();
  renderCart();
}

function removeItem(index) {
  cart.splice(index, 1);
  renderCart();
}

function changeQty(index, delta) {
  cart[index].qty = Math.max(1, Math.min(50, cart[index].qty + delta));
  renderCart();
}

function openCart() {
  document.getElementById('cartDrawer').classList.add('open');
  document.getElementById('cartOverlay').classList.add('open');
  document.body.style.overflow = 'hidden';
}

function closeCart() {
  document.getElementById('cartDrawer').classList.remove('open');
  document.getElementById('cartOverlay').classList.remove('open');
  document.body.style.overflow = '';
}

function renderCart() {
  var body = document.getElementById('cartBody');
  var foot = document.getElementById('cartFoot');
  var badge = document.getElementById('cartBadge');
  var bagBtnNum = document.querySelector('.bag-btn span');
  var totalQty = cart.reduce(function(sum, item) { return sum + item.qty; }, 0);
  badge.textContent = totalQty;
  bagBtnNum.textContent = totalQty;
  body.replaceChildren();

  if (cart.length === 0) {
    var empty = document.createElement('div');
    empty.className = 'cart-empty';
    var heading = document.createElement('b');
    heading.textContent = 'The bag is empty';
    var message = document.createElement('p');
    message.textContent = 'Add something from the gallery.';
    empty.append(heading, message);
    body.appendChild(empty);
    foot.style.display = 'none';
    return;
  }

  cart.forEach(function(item, index) {
    var row = document.createElement('div');
    row.className = 'cart-item';
    var image = document.createElement('img');
    image.src = item.image;
    image.alt = item.alt;
    var info = document.createElement('div');
    info.className = 'cart-item__info';
    var name = document.createElement('h4');
    name.className = 'cart-item__name';
    name.textContent = item.name;
    var category = document.createElement('p');
    category.className = 'cart-item__meta';
    category.textContent = item.cat;

    var controls = document.createElement('div');
    controls.style.cssText = 'display:flex;align-items:center;gap:.6rem;margin-top:.4rem;';
    var quantity = document.createElement('div');
    quantity.className = 'cart-item__qty';
    var decrease = document.createElement('button');
    decrease.type = 'button';
    decrease.textContent = '−';
    decrease.setAttribute('aria-label', 'Decrease quantity');
    decrease.addEventListener('click', function() { changeQty(index, -1); });
    var count = document.createElement('span');
    count.textContent = item.qty;
    var increase = document.createElement('button');
    increase.type = 'button';
    increase.textContent = '+';
    increase.setAttribute('aria-label', 'Increase quantity');
    increase.addEventListener('click', function() { changeQty(index, 1); });
    quantity.append(decrease, count, increase);

    var price = document.createElement('span');
    price.className = 'cart-item__price';
    price.textContent = formatRand(item.price);
    controls.append(quantity, price);
    var remove = document.createElement('button');
    remove.type = 'button';
    remove.className = 'cart-item__remove';
    remove.textContent = 'Remove';
    remove.addEventListener('click', function() { removeItem(index); });
    info.append(name, category, controls, remove);
    row.append(image, info);
    body.appendChild(row);
  });

  foot.style.display = 'block';
  var subtotal = cart.reduce(function(sum, item) {
    return sum + item.price * item.qty;
  }, 0);
  document.getElementById('cartSubtotal').textContent = formatRand(subtotal);
  document.getElementById('cartTotal').textContent = formatRand(subtotal);
}

function scrollGallery(direction) {
  var track = document.querySelector('.gallery__track');
  if (track) {
    var card = track.querySelector('.card');
    track.scrollBy({
      left: (card ? card.offsetWidth + 24 : 420) * direction,
      behavior: 'smooth'
    });
  }
}

function checkout() {
  var button = document.querySelector('.cart-checkout');
  if (!button || cart.length === 0) {
    return;
  }
  button.disabled = true;
  button.textContent = 'Preparing checkout…';
  document.getElementById('checkoutStatus').textContent = 'Loading secure checkout…';
  document.getElementById('cartBody').classList.add('checkout-hidden');
  document.getElementById('cartFoot').classList.add('checkout-hidden');
  document.getElementById('checkoutPanel').classList.add('is-visible');

  loadCatalog().then(function() {
    cart.forEach(function(item) {
      var latest = catalog[item.id];
      if (!latest) {
        throw new Error('A product in your bag is no longer available.');
      }
      item.name = latest.name;
      item.price = latest.price;
      item.image = latest.src;
      item.alt = latest.alt;
    });
    renderCart();
    return fetch('create-checkout-session.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({items: cart.map(function(item) {
        return {id: item.id, qty: item.qty};
      })})
    });
  }).then(function(response) {
    return response.json().then(function(data) {
      if (!response.ok) {
        throw new Error(data.error || 'Unable to start checkout.');
      }
      return data;
    });
  }).then(function(data) {
    if (!data.client_secret || !data.publishable_key) {
      throw new Error('Checkout setup is incomplete. Please try again later.');
    }
    if (typeof window.Stripe !== 'function') {
      throw new Error('Stripe.js could not be loaded. Check your internet connection and try again.');
    }
    var stripe = window.Stripe(data.publishable_key);
    var appearance = {
      theme: 'stripe',
      labels: 'auto',
      inputs: 'spaced',
      variables: {
        borderRadius: '4px',
        colorBackground: '#ffffff',
        colorDanger: '#df1b41',
        colorPrimary: '#0570de',
        colorSuccess: '#00c853',
        colorText: '#30313d',
        fontFamily: 'default',
        fontSizeBase: '16px',
        spacingUnit: '4px'
      }
    };
    var stripeCheckout = stripe.initCheckoutFormSdk({
      clientSecret: Promise.resolve(data.client_secret),
      appearance: appearance
    });
    var form = stripeCheckout.createForm({layout: 'expanded'});
    form.mount('#checkout-form');
    document.getElementById('checkoutStatus').textContent = '';
    return stripeCheckout.loadActions().then(function(result) {
      if (result.type !== 'success') {
        throw new Error('Secure checkout could not be loaded. Please return to your bag and try again.');
      }
      form.on('confirm', function(event) {
        result.actions.confirm({formConfirmEvent: event}).catch(function(error) {
          document.getElementById('checkoutStatus').textContent =
            error.message || 'Payment could not be confirmed. Please try again.';
        });
      });
    });
  }).catch(function(error) {
    document.getElementById('checkoutStatus').textContent =
      error.message || 'Unable to start checkout. Please try again.';
  }).then(function() {
    button.disabled = false;
    button.textContent = 'Checkout';
  });
}

function returnToBag() {
  document.getElementById('checkoutPanel').classList.remove('is-visible');
  document.getElementById('cartBody').classList.remove('checkout-hidden');
  document.getElementById('cartFoot').classList.remove('checkout-hidden');
}

function loadCatalog() {
  return fetch('products.php', {cache: 'no-store'}).then(function(response) {
    if (!response.ok) {
      throw new Error('Unable to load the product catalogue.');
    }
    return response.json();
  }).then(function(products) {
    if (!Array.isArray(products)) {
      throw new Error('The product catalogue response was invalid.');
    }
    var latestCatalog = Object.create(null);
    products.forEach(function(product) {
      if (typeof product.id !== 'string' || typeof product.name !== 'string' ||
          !Number.isSafeInteger(product.price) || product.price < 1 ||
          typeof product.src !== 'string' || typeof product.alt !== 'string') {
        throw new Error('The product catalogue contains invalid product data.');
      }
      latestCatalog[product.id] = product;
    });
    catalog = latestCatalog;

    document.querySelectorAll('[data-product-id]').forEach(function(card) {
      var product = catalog[card.dataset.productId];
      if (!product) {
        return;
      }
      var image = card.querySelector('.card__media img');
      image.src = product.src;
      image.alt = product.alt;
      card.querySelector('.product-price').textContent = formatRand(product.price);
      card.querySelector('[data-add-product]').disabled = false;
    });
  });
}

document.querySelectorAll('[data-add-product]').forEach(function(button) {
  button.addEventListener('click', function() {
    var card = button.closest('[data-product-id]');
    if (card) {
      addToCart(card.dataset.productId, card.querySelector('.card__cat').textContent);
    }
  });
});

loadCatalog().catch(function(error) {
  var status = document.getElementById('catalogStatus');
  status.hidden = false;
  status.textContent = error.message || 'Products are temporarily unavailable. Please try again later.';
});
