(function () {
  const navLinks = document.querySelectorAll('.cfg-nav [data-section]');
  const sections = document.querySelectorAll('.cfg-main .cfg-section');

  function showSection(sectionId) {
    sections.forEach((sec) => {
      const active = sec.id === sectionId;
      sec.hidden = !active;
      sec.classList.toggle('cfg-section--active', active);
    });
    navLinks.forEach((link) => {
      link.classList.toggle('is-active', link.dataset.section === sectionId);
    });
    const slug = sectionId.replace(/^cfg-|^crm-/, '');
    if (history.replaceState) {
      history.replaceState(null, '', '#' + slug);
    }
  }

  navLinks.forEach((link) => {
    link.addEventListener('click', (e) => {
      e.preventDefault();
      showSection(link.dataset.section);
    });
  });

  const hash = (location.hash || '').replace(/^#/, '');
  const map = {
    pipelines: 'crm-pipelines',
    company: 'cfg-company',
    ai: 'cfg-ai',
    gcal: 'cfg-gcal',
    calendar: 'cfg-gcal',
    payments: 'cfg-payments',
    dev: 'cfg-dev',
    developer: 'cfg-dev',
  };
  const initial = map[hash] || 'crm-pipelines';
  if (document.getElementById(initial)) {
    showSection(initial);
  } else if (sections[0]) {
    showSection(sections[0].id);
  }
})();
