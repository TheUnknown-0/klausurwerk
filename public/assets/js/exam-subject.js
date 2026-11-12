/**
 * Klausur-Stammdaten: Das Fach folgt dem gewählten Kurs, solange es niemand von Hand geändert hat.
 */
(function () {
    'use strict';
    const course = document.getElementById('course_id');
    const subject = document.getElementById('subject');
    if (!course || !subject) {
        return;
    }
    subject.addEventListener('input', () => {
        subject.dataset.subjectAuto = subject.value.trim() === '' ? '1' : '0';
    });
    const follow = () => {
        if (subject.dataset.subjectAuto !== '1') {
            return;
        }
        const option = course.options[course.selectedIndex];
        subject.value = option?.dataset.subject ?? '';
    };
    course.addEventListener('change', follow);
    follow();
})();
