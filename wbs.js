const stkForm = document.querySelector('.stk-form');

stkForm.addEventListener('submit', (e) => {
    e.preventDefault();

    let phone = document.querySelector('#phone').value.trim();
    let amount = document.querySelector('#amount').value.trim();

    if (!phone || !amount) {
        alert('Please fill in all fields');
        return;
    }

    if (phone.length !== 10 && phone.length !== 12 && phone.length !== 13) {
        alert('Invalid phone number!');
        return;
    }

    if (!phone.startsWith('+254') && !phone.startsWith('0') && !phone.startsWith('254')) {
        alert('Invalid phone number!');
        return;
    }

    if (phone.startsWith('+254')) {
        phone = phone.replace('+254', '254');
    } else if (phone.startsWith('0')) {
        phone = phone.replace('0', '254');
    }

    // Convert amount and phone to strings
    amount = amount.toString();
    phone = phone.toString();

    // submit the form with the phone and amount values
    const formData = new FormData();
    formData.append('phone', phone);
    formData.append('amount', amount);

    fetch('formhandler.php', {
        method: 'POST',
        body: formData
    }).then(response => response.json())
        .then(data => {
            alert(data); // Show server response
        })
        .catch(error => {
            alert('Error: ' + error);
        });
});