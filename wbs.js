const payForm = document.getElementById("pay-form");
const loadingIndicator = document.getElementById("loader");

function displayForm() {
    payForm.classList.remove("hidden");
    payForm.classList.add("shown");

}
function hideForm() {
    payForm.classList.remove("shown");
    payForm.classList.add("hidden")
}

function showLoader() {
    loadingIndicator.classList.remove("loader-wrapper-hidden");
    loadingIndicator.classList.add("loader-wrapper");
}

function hideLoader() {
    loadingIndicator.classList.remove("loader-wrapper");
    loadingIndicator.classList.add("loader-wrapper-hidden");
}

payForm.addEventListener("submit", function (event) {
    event.preventDefault(); // Prevent the default form submission behavior

    let phoneNumber = document.getElementById('phone').value.trim();
    const amount = document.getElementById('amount').value.trim();

    if (phoneNumber === "" || amount === "") {
        alert("Please fill in all fields.");
        return;
    }
    else if (isNaN(amount) || amount <= 0) {
        alert("Amount must be a positive integer.");
        return;
    }
    else if (!/^(07|2547|01)\d{8}$/.test(phoneNumber)) {
        alert("Enter a valid phone number format.");
        return;
    }
    else if (/^0\d{9}$/.test(phoneNumber)) {
        phoneNumber = phoneNumber.replace(/^0/, '254');
    }

    const formData = new FormData(payForm);

    // Show loading indicator
    showLoader();

    fetch('https://bf525480c0ed.ngrok-free.app/WBS/formhandler.php', {
        method: 'POST',
        body: formData
    })
        .then(response => response.text()) // expecting plain text from PHP
        .then(data => {
            hideLoader();
            alert(data); // Show the server's message
            console.log(data);

        })
        .catch(error => {
            // Hide loading indicator
            hideLoader();
            alert("Error submitting the form: " + error.message);
            console.error(error);
        });
    // Reset the form after submission
    payForm.reset();

});
let loginForm = document.getElementById('login-form');
function showLoginForm() {
    loginForm.classList.remove('hidden');
    loginForm.classList.add('shown');
}