# PACKAGE TITLE
pkg_name=NULL
org_name=NULL
pkg_name_tag={{package_name}}

# DOCUMENTATION
project_doc_path=app/Resources/doc
bundle_doc_path=Resources/doc
doc_path_tag={{doc_path}}

# TESTS
project_test_path=tests
bundle_test_path=Tests
test_path_tag={{test_path}}

# GITHUB URL
github_repo=$(org_name)/$(pkg_name)
github_url=https://github.com/$(github_repo).git
github_url_tag={{github_url}}

#CLONE
clone_path=".tmp/$(pkg_name)"



#################

first-install:
	echo "installing hub cli...\n"
	wget -qO- https://github.com/github/hub/releases/download/v2.3.0-pre9/hub-linux-amd64-2.3.0-pre9.tgz | tar xvz -C /hub-bin
	cd hub-bin
	sudo ./install
	cd .. && rm -Rf hub-bin
	git config --global hub.protocol https
 

clone:
	hub clone $(github_repo) $(clone_path)
	cd $(clone_path) && git checkout -b update-branch && \
	git config user.name "BlastCI" && \
	git config user.email "r.et.d@libre-informatique.fr"

apply-skeleton:
	rsync -avh bundle-skeleton/ $(clone_path)/
	sed -i -- 's|$(github_url_tag)|$(github_url)|g' $(clone_path)/.travis.yml

project-doc:
	rsync -avh $(clone_path)/Resources $(clone_path)/app/
	rm $(clone_path)/Resources

commit:
	cd $(clone_path) && git add -A && git commit -m "DevKit updates" && \
	hub fork && \
	git push -u BlastCI update-branch
	
del-branch:
	cd $(clone_path) && git push origin --delete update-branch
	
pull-request:
	cd $(clone_path) && hub pull-request

print:
	echo REPO : $(github_url)

bundle: clone apply-skeleton

project: clone apply-skeleton travis project-doc



