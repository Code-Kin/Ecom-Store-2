var cart = [];
var catalog = Object.create(null);

const API_URL = "https://xxxxxxxxxx.execute-api.eu-north-1.amazonaws.com";

async function loadProducts() {
  const response = await fetch(`${API_URL}/products`);
  if (!response.ok) {
    throw new Error('Unable to load products from the API.');
  }
  const products = await response.json();
  return products;
}

async function updateCart(cartData) {
  const response = await fetch(`${API_URL}/cart`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(cartData)
  });
  if (!response.ok) {
    throw new Error('Unable to update the cart.');
  }
  const result = await response.json();
  console.log(result);
  return result;
}

function formatRand(amount) {
  return 'R ' + amount.toLocaleString('en-ZA');
}
