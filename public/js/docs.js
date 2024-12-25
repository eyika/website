document.addEventListener('DOMContentLoaded', () => {
    // Check for dark mode preference in localStorage
    if (localStorage.getItem('dark-mode') === 'enabled') {
        document.body.classList.add('dark-mode');
        document.getElementById('mode-toggle').textContent = '🌞';
    } else {
        document.body.classList.remove('dark-mode');
        document.getElementById('mode-toggle').textContent = '🌙';
    }

    // Expand or Collapse a Section in the Sidebar
    // const sections = document.querySelectorAll('.nav-header');
    // sections.forEach(section => {
    //     section.addEventListener('click', () => {
    //         const target = section.nextElementSibling;
    //         if (target.style.display === 'block') {
    //             target.style.display = 'none';
    //         } else {
    //             target.style.display = 'block';
    //         }
    //     });
    // });

    // Toggle between expanding and collapsing sections
    const menuItems = document.querySelectorAll('.expandable-menu > a');
    
    document.querySelectorAll('pre').forEach(pre => {
        const copyBtn = document.createElement('button');
        copyBtn.className = 'copy-btn';
        copyBtn.textContent = 'Copy';

        copyBtn.addEventListener('click', () => {
            const code = pre.querySelector('code').innerText;
            navigator.clipboard.writeText(code).then(() => {
                copyBtn.textContent = 'Copied!';
                setTimeout(() => copyBtn.textContent = 'Copy', 2000);
            });
        });

        pre.style.position = 'relative'; // Ensure the pre is positioned for absolute child
        pre.appendChild(copyBtn);
    });

    menuItems.forEach(item => {
        item.addEventListener('click', function (event) {
            const nextElement = item.nextElementSibling;
            
            // If the next sibling is a collapsible section, toggle its visibility
            if (nextElement && nextElement.classList.contains('collapsible')) {
                toggleSection(nextElement);
            }
        });
    });

    function toggleSection(section) {
        // Collapse all sections first
        const allSections = document.querySelectorAll('.collapsible');
        allSections.forEach(sec => {
            if (sec !== section) {
                sec.classList.add('collapsed');
            }
        });

        // Toggle the clicked section's visibility
        section.classList.toggle('collapsed');
    }
});

function toggleSidebar() {
    const sidebar = document.getElementById('sidebar');
    sidebar.classList.toggle('open');
}

// Toggle Dark/Light Mode
function toggleDarkMode() {
    const body = document.body;
    const modeToggleButton = document.getElementById('mode-toggle');
    
    if (body.classList.contains('dark-mode')) {
        body.classList.remove('dark-mode');
        localStorage.setItem('dark-mode', 'disabled');
        modeToggleButton.textContent = '🌙';
    } else {
        body.classList.add('dark-mode');
        localStorage.setItem('dark-mode', 'enabled');
        modeToggleButton.textContent = '🌞';
    }
}