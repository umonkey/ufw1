set et ts=4 sts=4 sw=4 et

" TEMPLATES for new files
augroup templates
  au!
  autocmd BufNewFile bin/*.php 0r .vim/snippets/bin.php
  autocmd BufNewFile src/Controllers/*Controller.php 0r .vim/snippets/controller.php
  autocmd BufNewFile src/Services/*Repository.php 0r .vim/snippets/repository.php
  autocmd BufNewFile src/Services/*Service.php 0r .vim/snippets/service.php
  autocmd BufNewFile src/*Action.php 0r !php -f .vim/get-action-template.php %
  autocmd BufNewFile src/*Responder.php 0r !php -f .vim/get-responder-template.php %
  autocmd BufNewFile templates/admin/*.twig 0r .vim/snippets/admin.twig
  autocmd BufNewFile templates/fields/*.twig 0r .vim/snippets/field.twig
  autocmd BufNewFile templates/layouts/*.twig 0r .vim/snippets/layout.twig
  autocmd BufNewFile templates/list/*.twig 0r .vim/snippets/list.twig
  autocmd BufNewFile templates/pages/*.twig 0r .vim/snippets/page.twig

  autocmd BufNewFile *.php,*.twig %substitute#\[:VIM_EVAL:\]\(.\{-\}\)\[:END_EVAL:\]#\=eval(submatch(1))#ge
augroup END

au FileType php set foldmethod=syntax
