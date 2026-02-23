// document.addEventListener('click', function (e) {
//     const wrapper = e.target.closest('.ispag-inline-edit');
//     if (!wrapper || wrapper.dataset.readonly === "true") return;

//     const field = wrapper.dataset.name;
//     const value = wrapper.dataset.value;
//     const id = wrapper.dataset.achat;
//     const type = field === 'TimestampDateCreation' ? 'date' : 'text';

//     const input = document.createElement('input');
//     input.type = type;
//     input.value = value;
//     input.className = 'ispag-edit-input';
//     input.style.width = '100%';

//     wrapper.replaceWith(input);
//     input.focus();

//     const save = () => {
//         fetch(ajaxurl, {
//             method: 'POST',
//             headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
//             body: new URLSearchParams({
//                 action: 'ispag_update_achat_field',
//                 achat_id: id,
//                 field_name: field,
//                 field_value: input.value
//             })
//         }).then(() => {
//             const newSpan = document.createElement('span');
//             newSpan.className = 'ispag-inline-edit';
//             newSpan.dataset.name = field;
//             newSpan.dataset.value = input.value;
//             newSpan.dataset.achat = id;
//             newSpan.innerHTML = `${input.value} <span class="edit-icon"><img draggable="false" role="img" class="emoji" alt="✏️" src="https://s.w.org/images/core/emoji/15.1.0/svg/270f.svg" width="14" height="14"></span>`;
//             input.replaceWith(newSpan);
//         });
//     };

//     input.addEventListener('blur', save);
//     input.addEventListener('keydown', e => {
//         if (e.key === 'Enter') {
//             e.preventDefault();
//             save();
//         }
//     });
// });
