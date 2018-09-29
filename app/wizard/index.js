import Settings from 'git-sync';
import request from 'admin/utils/request';
import toastr from 'admin/utils/toastr';
import { config } from 'grav-config';
import $ from 'jquery';

const WIZARD = $('[data-remodal-id="wizard"]');
const RESET_LOCAL = $('[data-remodal-id="reset-local"]');
const SERVICES = { 'github': 'github.com', 'bitbucket': 'bitbucket.org', 'gitlab': 'gitlab.com', 'allothers': 'allothers.repo' };
const TEMPLATES = {
    REPO_URL: 'https://{placeholder}/getgrav/grav.git'
};

const openWizard = () => {
    const modal = WIZARD.remodal({ closeOnConfirm: false });
    const previous = WIZARD.find('[data-gitsync-action="previous"]');
    const next = WIZARD.find('[data-gitsync-action="next"]');
    const save = WIZARD.find('[data-gitsync-action="save"]');

    STEP = 0;

    WIZARD.find(`form > [class^=step-]:not(.step-${STEP}) > .panel`).hide().removeClass('hidden');
    WIZARD.find(`form > [class="step-${STEP}"] > .panel`).show();

    next.removeClass('hidden');
    previous.addClass('hidden');
    save.addClass('hidden');

    const webhook = $('[name="data[webhook]"]').val();
    const webhook_secret = $('[name="data[webhook_secret]"]').val();
    $('[name="gitsync[repository]"]').trigger('change');
    $('[name="gitsync[webhook]"]').val(webhook);
    $('[name="gitsync[webhook_secret]"]').val(webhook_secret);
    $('.gitsync-webhook').text(webhook);

    modal.open();
};

const disableButton = (next) => {
    next
        .attr('disabled', 'disabled')
        .addClass('hint--top');
};

const enableButton = (next) => {
    next
        .attr('disabled', null)
        .removeClass('hint--top');
};

let STEP = 0;
let STEPS = 0;
let SERVICE = null;

$(document).on('closed', WIZARD, function(e) {
    STEP = 0;
});

$(document).on('click', '[data-gitsync-useraction]', (event) => {
    event.preventDefault();
    const target = $(event.target).closest('[data-gitsync-useraction]');
    const action = target.data('gitsyncUseraction');
    const URI = `${config.current_url}.json`;

    switch (action) {
        case 'wizard':
            openWizard();
            break;
        case 'sync':
            const relativeURI = target.data('gitsync-uri');
            target.find('i').removeClass('fa-cloud fa-git').addClass('fa-circle-o-notch fa-spin');

            request(relativeURI || URI, {
                method: 'post',
                body: { task: 'synchronize' }
            }, () => {
                target.find('i').removeClass('fa-circle-o-notch fa-spin').addClass(relativeURI ? 'fa-git' : 'fa-cloud');
            });
            break;
        case 'reset':
            const modal = RESET_LOCAL.remodal({ closeOnConfirm: false });
            modal.open();

            if (!RESET_LOCAL.data('_reset_event_set_')) {
                RESET_LOCAL.find('[data-gitsync-action="reset-local"]').one('click', () => {
                    modal.close();
                    RESET_LOCAL.data('_reset_event_set_', true);
                    target.find('i').removeClass('fa-history').addClass('fa-circle-o-notch fa-spin');
                    request(URI, {
                        method: 'post',
                        body: { task: 'resetlocal' }
                    }, () => {
                        RESET_LOCAL.data('_reset_event_set_', false);
                        target.find('i').removeClass('fa-circle-o-notch fa-spin').addClass('fa-history');
                    });
                });
            }
            break;
    }
});

$(document).on('click', '[data-gitsync-action]', (event) => {
    event.preventDefault();

    const target = $(event.target).closest('[data-gitsync-action]');
    const previous = WIZARD.find('[data-gitsync-action="previous"]');
    const next = WIZARD.find('[data-gitsync-action="next"]');
    const save = WIZARD.find('[data-gitsync-action="save"]');
    const action = target.data('gitsyncAction');
    const user = $('[name="gitsync[repo_user]"]').val();
    const password = $('[name="gitsync[repo_password]"]').val();
    const repository = $('[name="gitsync[repo_url]"]').val();
    const webhook = $('[name="gitsync[webhook]"]').val();
    const webhook_enabled = $('[name="gitsync[webhook_enabled]"]').is(':checked');
    const webhook_secret = $('[name="gitsync[webhook_secret]"]').val();

    if (target.attr('disabled')) {
        return;
    }

    let error = [];

    if (!user) {
        error.push('Username is missing.');
    }
    /*
    if (!password) {
        error.push('Password is missing.');
    }
    */
    if (!repository) {
        error.push('Repository is missing.');
    }

    if (['save', 'test'].includes(action)) {
        if (error.length) {
            toastr.error(error.join('<br />'));

            return false;
        }
    }

    if (action === 'save') {
        const folders = $('[name="gitsync[folders]"]:checked').map((i, item) => item.value);
        $('[name="data[repository]"]').val(repository);
        $('[name="data[user]"]').val(user);
        $('[name="data[password]"]').val(password);
        $('[name="data[webhook]"]').val(webhook);
        $(`[name="data[webhook_enabled]"][value="${webhook_enabled ? 1 : 0}"]`).prop('checked', true);
        $('[name="data[webhook_secret]"]').val(webhook_secret);

        const dataFolders = $('[name="data[folders][]"]');
        if (dataFolders && dataFolders[0] && dataFolders[0].selectize) {
            dataFolders[0].selectize.setValue(folders.toArray());
        }

        $('[name="task"][value="save"]').trigger('click');

        return false;
    }

    if (action === 'test') {
        const URI = `${config.current_url}.json`;
        const test = global.btoa(JSON.stringify({ user, password, repository }));

        request(URI, {
            method: 'post',
            body: { test, task: 'testConnection' }
        });

        return false;
    }

    WIZARD.find(`.step-${STEP} > .panel`).slideUp();
    STEP += action === 'next' ? +1 : -1;
    WIZARD.find(`.step-${STEP} > .panel`).slideDown();

    save.addClass('hidden');

    if (action === 'next') {
        previous.removeClass('hidden');
    }

    if (STEP <= 0) {
        previous.addClass('hidden');
        enableButton(next);
    }

    if (STEP > 0) {
        next.removeClass('hidden');
    }

    if (STEP === 1) {
        const selectedRepo = $('[name="gitsync[repository]"]:checked');
        if (!selectedRepo.length) {
            disableButton(next);
        } else {
            enableButton(next);
        }
    }

    if (STEP === 2) {
        const repoURL = $('[name="gitsync[repo_url]"]').val();
        if (!repoURL.length) {
            disableButton(next);
        } else {
            enableButton(next);
        }
    }

    if (STEP === STEPS) {
        next.addClass('hidden');
        previous.removeClass('hidden');
        save.removeClass('hidden');
    }
});

$(document).on('change', '[name="gitsync[repository]"]', () => {
    enableButton(WIZARD.find('[data-gitsync-action="next"]'));
});

$(document).on('input', '[name="gitsync[repo_url]"]', (event) => {
    const target = $(event.currentTarget);
    const value = target.val();
    const next = WIZARD.find('[data-gitsync-action="next"]');

    if (value.length) {
        enableButton(next);
    } else {
        disableButton(next);
    }
});

$(document).on('keyup', '[data-gitsync-uribase] [name="gitsync[webhook]"]', (event) => {
    const target = $(event.currentTarget);
    const value = target.val();
    $('.gitsync-webhook').text(value);
});

$(document).on('keyup', '[data-gitsync-uribase] [name="gitsync[webhook_secret]"]', (event) => {
    $('[data-gitsync-uribase] [name="gitsync[webhook_enabled]"]').trigger('change');
});

$(document).on('change', '[data-gitsync-uribase] [name="gitsync[webhook_enabled]"]', (event) => {
    const target = $(event.currentTarget);
    const checked = target.is(':checked');
    const secret = $('[name="gitsync[webhook_secret]"]').val();
    target.closest('.webhook-secret-wrapper').find('label:last-child')[checked ? 'removeClass' : 'addClass']('hidden');
    $('.gitsync-webhook-secret').html(!checked || !secret.length ? '<em>leave empty</em>' : `<code>${secret}</code>`);
});

$(document).on('change', '[name="gitsync[repository]"]', (event) => {
    const target = $(event.target);
    if (!target.is(':checked')) {
        return;
    }

    SERVICE = target.val();

    Object.keys(SERVICES).forEach((service) => {
        WIZARD.find(`.hidden-step-${service}`)[service === SERVICE ? 'removeClass' : 'addClass']('hidden');
        if (service === SERVICE) {
            WIZARD.find('.webhook-secret-wrapper')[service === 'bitbucket' ? 'addClass' : 'removeClass']('hidden');
            WIZARD
                .find('input[name="gitsync[repo_url]"][placeholder]')
                .attr('placeholder', TEMPLATES.REPO_URL.replace(/\{placeholder\}/, SERVICES[service]));
        }
    });

});

$(document).on('click', '[data-access-tokens-details]', (event) => {
    event.preventDefault();

    const button = $(event.currentTarget);
    const panel = button.closest('.access-tokens').find('.access-tokens-details');

    panel.slideToggle(250, () => {
        const isVisible = panel.is(':visible');
        const icon = button.find('.fa');

        icon.removeClass('fa-chevron-down fa-chevron-up').addClass(`fa-chevron-${isVisible ? 'up' : 'down'}`);
    });
});

const showNotices = (element) => {
    const target = $(element);

    const selection = target.val().replace(/\//g, '-');
    const column = target.closest('.columns').find('.column:last');

    column.find('[class*="description-"]').addClass('hidden');
    column.find(`.description-${selection}`).removeClass('hidden').hide().fadeIn({
        duration: 250
    });
};

$(document).on('input', '[data-remodal-id="wizard"] .step-4 input[type="checkbox"]', (event) => {
    const target = $(event.currentTarget);
    if (!target.is(':checked')) {
        return;
    }

    showNotices(target);
});

$(document).on('mouseenter', '[data-remodal-id="wizard"] .step-4 .info-desc', (event) => {
    const target = $(event.currentTarget).siblings('input[type="checkbox"]');
    showNotices(target);
});

$(document).on('mouseleave', '[data-remodal-id="wizard"] .step-4 label', (event) => {
    const target = $(event.currentTarget);
    const container = target.closest('.columns');
    const column = container.find('.column:last-child');

    column.find('[class*="description-"]').addClass('hidden');
});

$(document).on('mouseleave', '[data-remodal-id="wizard"] .columns .column:first-child', (event) => {
    const target = $(event.currentTarget);
    const column = target.siblings('.column');

    column.find('[class*="description-"]').addClass('hidden');
});

$(document).ready(() => {
    STEPS = WIZARD.find('[class^="step-"]').length - 1;
    WIZARD.wrapInner('<form></form>');
    RESET_LOCAL.wrapInner('<form></form>');

    if (WIZARD.length && (Settings.first_time || !Settings.git_installed)) {
        openWizard();
    }
});

export default Settings;
