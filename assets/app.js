// The stateless CSRF protection is a listener on every submit: it writes the
// double-submit cookie and the token the server expects. The controller is
// loaded lazily, when a field carries its data-controller attribute, and the
// fields the Form component draws carry it. A form written by hand carries
// none, so on a page holding only those the listener would never be installed.
// The token would stay the placeholder, the server would refuse it once it has
// seen a real one, and the form would be rejected. Importing the controller
// here installs the listener on every page instead.
import './controllers/csrf_protection_controller.js';
import './stimulus_bootstrap.js';
import './styles/app.css';
