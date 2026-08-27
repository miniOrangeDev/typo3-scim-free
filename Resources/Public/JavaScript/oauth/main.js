function initScimModuleTabs() {
    const tabyInput = document.getElementById('taby');
    const session = tabyInput ? tabyInput.value : '';

    const validTabs = [
        'Scim_Configuration',
        'Sync_Configuration',
        'Attribute_Mapping',
        'Role_Mapping',
        'Upgrade_Manager',
    ];
    const tabToActivate = validTabs.includes(session) ? session : 'Scim_Configuration';
    openTab(tabToActivate);

    document.querySelectorAll('.tablinks.button').forEach(button => {
        button.addEventListener('click', () => {
            removeFlashMessage();
            const tabName = button.id.replace('_Tab', '');
            openTab(tabName);
        });
    });
}

function initCustomAttributeAccordion() {
    const accordion = document.getElementById('scim_custom_attr_accordion');
    if (!accordion) {
        return;
    }

    accordion.querySelectorAll('.scim-attr-accordion-header').forEach(header => {
        header.addEventListener('click', () => {
            const item = header.closest('.scim-attr-accordion-item');
            if (!item) {
                return;
            }

            const willExpand = !item.classList.contains('scim-attr-accordion-item-expanded');

            accordion.querySelectorAll('.scim-attr-accordion-item').forEach(otherItem => {
                const isTarget = otherItem === item;
                const expand = isTarget && willExpand;
                otherItem.classList.toggle('scim-attr-accordion-item-expanded', expand);

                const otherHeader = otherItem.querySelector('.scim-attr-accordion-header');
                const otherBody = otherItem.querySelector('.scim-attr-accordion-body');
                if (otherHeader) {
                    otherHeader.setAttribute('aria-expanded', expand ? 'true' : 'false');
                }
                if (otherBody) {
                    otherBody.hidden = !expand;
                }
            });
        });
    });
}

function initScimCopyButtons() {
    document.querySelectorAll('.scim-copy-btn[data-copy-target]').forEach(button => {
        button.addEventListener('click', () => {
            const targetId = button.getAttribute('data-copy-target');
            const input = document.getElementById(targetId);
            if (!input || !input.value) {
                return;
            }

            const copyValue = () => {
                showCopyFeedback(button);
            };

            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(input.value).then(copyValue).catch(() => {
                    fallbackCopy(input, copyValue);
                });
                return;
            }

            fallbackCopy(input, copyValue);
        });
    });
}

function fallbackCopy(input, onSuccess) {
    input.focus();
    input.select();
    input.setSelectionRange(0, input.value.length);

    try {
        if (document.execCommand('copy')) {
            onSuccess();
        }
    } catch (error) {
        // Ignore clipboard failures silently.
    }
}

function showCopyFeedback(button) {
    button.classList.add('scim-copy-btn--copied');
    button.setAttribute('title', 'Copied!');

    window.setTimeout(() => {
        button.classList.remove('scim-copy-btn--copied');
        button.setAttribute('title', 'Copy to clipboard');
    }, 1500);
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        initScimModuleTabs();
        initCustomAttributeAccordion();
        initScimCopyButtons();
    });
} else {
    initScimModuleTabs();
    initCustomAttributeAccordion();
    initScimCopyButtons();
}

function removeFlashMessage() {
    document.querySelectorAll('.typo3-messages').forEach(el => el.remove());
}

function openTab(activeTab) {
    const tabcontent = document.getElementsByClassName('tabcontent');
    for (let i = 0; i < tabcontent.length; i++) {
        tabcontent[i].style.display = 'none';
    }

    const tablinks = document.getElementsByClassName('tablinks');
    for (let i = 0; i < tablinks.length; i++) {
        tablinks[i].classList.remove('active');
    }

    const tabPanel = document.getElementById(activeTab);
    if (tabPanel) {
        tabPanel.style.display = 'block';
    }

    const tabBtn = document.getElementById(activeTab + '_Tab');
    if (tabBtn) {
        tabBtn.classList.add('active');
    }
}
