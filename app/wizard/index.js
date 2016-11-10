import Settings from 'git-sync';
import $ from 'jquery';

const WIZARD = $('[data-remodal-id="wizard"]');
const SERVICES = { 'github': 'github.com', 'bitbucket': 'bitbucket.org', 'gitlab': 'gitlab.com' };
const TEMPLATES = {
    REPO_URL: 'https://{placeholder}/getgrav/grav.git'
};
let STEP = 0;
let STEPS = 0;
let SERVICE = null;

$(document).on('closed', WIZARD, function (e) {
    STEP = 0;
});

$(document).on('click', '[data-gitsync-action]', (event) => {
    event.preventDefault();

    const target = $(event.target).closest('[data-gitsync-action]');
    const previous = WIZARD.find('[data-gitsync-action="previous"]');
    const next = WIZARD.find('[data-gitsync-action="next"]');
    const save = WIZARD.find('[data-gitsync-action="save"]');
    const action = target.data('gitsyncAction');

    WIZARD.find(`.step-${STEP} > .panel`).slideUp();
    STEP += action === 'next' ? +1 : -1;
    WIZARD.find(`.step-${STEP} > .panel`).slideDown();

    save.addClass('hidden');

    if (action === 'next') {
        previous.removeClass('hidden');
    }

    if (STEP <= 0) {
        previous.addClass('hidden');
    }

    if (STEP > 0) {
        next.removeClass('hidden');
    }

    if (STEP === STEPS) {
        next.addClass('hidden');
        previous.removeClass('hidden');
        save.removeClass('hidden');
    }
});

$(document).on('change', '[name="gitsync[repository]"]', (event) => {
    const target = $(event.target);
    SERVICE = target.val();

    Object.keys(SERVICES).forEach((service) => {
        WIZARD.find(`.hidden-step-${service}`)[service === SERVICE ? 'removeClass' : 'addClass']('hidden');
        if (service === SERVICE) {
            WIZARD
                .find('input[name="gitsync[repo_url]"][placeholder]')
                .attr('placeholder', TEMPLATES.REPO_URL.replace(/\{placeholder\}/, SERVICES[service]));
        }
    });

});

$(document).ready(() => {
    STEPS = WIZARD.find('[class^="step-"]').length - 1;
    WIZARD.wrapInner("<form></form>");
    WIZARD.find(`form > [class^=step-]:not(.step-${STEP}) > .panel`).hide().removeClass('hidden');

    if (Settings.first_time) {
        const modal = WIZARD.remodal({ closeOnConfirm: false });
        modal.open();
    }
});

export default Settings;
